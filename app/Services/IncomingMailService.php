<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use App\Support\Mail\ParsedIncomingMail;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Message;

/**
 * Creates issues from inbound email, matching Redmine's MailHandler:
 * a `[project-identifier]` prefix on the subject targets that project,
 * otherwise the configured default project is used; the sender's address
 * must match an existing, authorized local user, since — unlike Redmine's
 * optional unknown_user/no_permission_check flags — this app has no
 * account-provisioning-from-email path and silently trusting a From:
 * header to create issues as an arbitrary user would be a real hole.
 *
 * A subject shaped like "[... #123]" is treated as a reply to issue
 * #123 instead — its body is added as a comment rather than creating a
 * new issue, matching Redmine's receive_issue_reply.
 *
 * The body is also scanned for a handful of Redmine-style keyword
 * command lines ("Status: Closed", one per line) that override an
 * attribute on the created/replied-to issue, matching Redmine's
 * MailHandler#issue_attributes_from_keywords — narrowed to
 * status/priority/assigned_to/done_ratio/tracker/category/fixed_version/
 * start_date/due_date/estimated_hours/is_private/parent_issue (this app
 * has no i18n, so unlike Redmine the keyword label itself is a fixed
 * English string rather than translated per Setting.default_language —
 * "parent issue" is chosen to match this app's own attribute name rather
 * than Redmine's own English UI label "Parent task", which none of this
 * app's other keyword labels track literally either, e.g. "done ratio"
 * vs Redmine's "% Done"). Custom-field keywords are intentionally not
 * recognized — a deliberately narrower grammar than Redmine's, same
 * scope-cut RepositorySyncService's commit-keyword parsing already takes
 * for `@Nh` time logging.
 */
final class IncomingMailService
{
    /**
     * Keyword label (lowercase, spaces) => the Issue attribute it sets.
     *
     * @var array<string, string>
     */
    private const array KEYWORD_ATTRIBUTES = [
        'status' => 'status_id',
        'priority' => 'priority_id',
        'assigned to' => 'assigned_to_id',
        'done ratio' => 'done_ratio',
        'tracker' => 'tracker_id',
        'category' => 'category_id',
        'fixed version' => 'fixed_version_id',
        'start date' => 'start_date',
        'due date' => 'due_date',
        'estimated hours' => 'estimated_hours',
        'private' => 'is_private',
        'parent issue' => 'parent_id',
    ];

    public function __construct(
        private readonly IssueService $issues,
        private readonly AuthorizationService $authorization,
    ) {}

    public function fetchAndProcess(): int
    {
        if (! Setting::get('incoming_mail_enabled', false)) {
            return 0;
        }

        if (blank(config('imap.accounts.default.host'))) {
            return 0;
        }

        try {
            $client = app(ClientManager::class)->account('default');
            $client->connect();
        } catch (ConnectionFailedException $e) {
            Log::warning('Incoming mail: could not connect to the configured mailbox.', ['error' => $e->getMessage()]);

            return 0;
        }

        $messages = $client->getFolder('INBOX')->messages()->whereUnseen()->get();
        $processed = 0;

        foreach ($messages as $message) {
            try {
                if ($this->createIssueFromMail($this->parse($message)) !== null) {
                    $processed++;
                }
            } catch (Throwable $e) {
                Log::warning('Incoming mail: failed to process a message.', ['error' => $e->getMessage()]);
            } finally {
                $message->setFlag('Seen');
            }
        }

        return $processed;
    }

    private function parse(Message $message): ParsedIncomingMail
    {
        $attachments = [];

        foreach ($message->getAttachments() as $attachment) {
            $filename = (string) $attachment->name;

            if ($this->filenameExcluded($filename)) {
                continue;
            }

            $attachments[] = ['filename' => $filename, 'content' => (string) $attachment->content];
        }

        $body = $this->truncateBody($this->resolveBody($message->getTextBody(), $message->getHTMLBody()));

        return new ParsedIncomingMail(
            subject: (string) $message->subject,
            body: $body,
            fromEmail: (string) ($message->from[0]->mail ?? ''),
            attachments: $attachments,
        );
    }

    /**
     * Picks the text or HTML body per Setting::mail_handler_preferred_body_part
     * (matching Redmine's own setting of the same name), falling back to
     * whichever part is actually present when the preferred one is empty.
     * The HTML part is stripped to plain text since issue descriptions in
     * this app are rendered as Markdown, not raw HTML.
     */
    public function resolveBody(string $textBody, string $htmlBody): string
    {
        if (Setting::get('mail_handler_preferred_body_part', 'plain') === 'html') {
            return $htmlBody !== '' ? trim(strip_tags($htmlBody)) : $textBody;
        }

        return $textBody !== '' ? $textBody : trim(strip_tags($htmlBody));
    }

    /**
     * Truncates the body at the first line matching one of
     * Setting::mail_handler_body_delimiters (one plain-text line per
     * setting line) — matches Redmine's own delimiter truncation, used to
     * strip quoted reply chains and signatures from the stored body.
     */
    public function truncateBody(string $body): string
    {
        $delimiters = collect(explode("\n", (string) Setting::get('mail_handler_body_delimiters', '')))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->all();

        if ($delimiters === []) {
            return $body;
        }

        $lines = explode("\n", $body);

        foreach ($lines as $index => $line) {
            if (in_array(trim($line), $delimiters, true)) {
                return trim(implode("\n", array_slice($lines, 0, $index)));
            }
        }

        return $body;
    }

    /**
     * Matches Setting::mail_handler_excluded_filenames (comma-separated
     * glob patterns, e.g. "*.ics, winmail.dat") against an attachment's
     * filename — same comma-separated-list convention as
     * attachment_extensions_allowed/denied elsewhere in this app.
     */
    public function filenameExcluded(string $filename): bool
    {
        $patterns = collect(explode(',', (string) Setting::get('mail_handler_excluded_filenames', '')))
            ->map(fn (string $pattern) => trim($pattern))
            ->filter();

        return $patterns->contains(fn (string $pattern) => fnmatch($pattern, $filename, FNM_CASEFOLD));
    }

    public function createIssueFromMail(ParsedIncomingMail $mail): ?Issue
    {
        $author = User::query()->where('email', $mail->fromEmail)->first();

        if ($author === null) {
            return null;
        }

        // A subject containing "[... #123]" is a reply to a notification
        // about issue #123 — matches Redmine's MailHandler::
        // ISSUE_REPLY_SUBJECT_RE, routing to a comment on the existing
        // issue instead of creating a new one. This app doesn't send
        // outbound notification emails yet, so nothing currently
        // generates a subject shaped like that automatically, but a
        // sender can still trigger it by including the pattern
        // themselves, and it's ready for whenever notifications exist.
        if (preg_match('/\[(?:[^\]]*\s+)?#(\d+)\]/', $mail->subject, $matches) === 1) {
            return $this->receiveIssueReply((int) $matches[1], $mail, $author);
        }

        $project = $this->resolveProject($mail->subject);

        if ($project === null || ! $this->authorization->can($author, 'add_issues', $project)) {
            return null;
        }

        $trackerId = (int) Setting::get('incoming_mail_default_tracker_id', 0);

        if (! $project->trackers->contains('id', $trackerId)) {
            return null;
        }

        $statusId = (int) Setting::get('incoming_mail_default_status_id', 0);
        $priorityId = Enumeration::query()->ofType(EnumerationType::IssuePriority)->where('is_default', true)->value('id');

        if ($statusId === 0 || $priorityId === null) {
            return null;
        }

        $subject = mb_substr($this->stripProjectPrefix($mail->subject), 0, 255);
        ['attributes' => $keywordAttributes, 'body' => $body] = $this->extractKeywordAttributes($mail->body, $project, $author);

        $issue = $this->issues->create([
            'project_id' => $project->id,
            'tracker_id' => $trackerId,
            'status_id' => $statusId,
            'priority_id' => $priorityId,
            'subject' => $subject !== '' ? $subject : '(no subject)',
            'description' => $body,
            ...$keywordAttributes,
        ], $author);

        foreach ($mail->attachments as $attachment) {
            if ($attachment['content'] === '') {
                continue;
            }

            // Isolated per attachment — e.g. one that fails media-library's
            // max_file_size check shouldn't abort the loop and leave the
            // issue (already created above) missing every attachment after
            // it, or get the whole message misreported as a failure when
            // most of it succeeded.
            try {
                $issue->addMediaFromString($attachment['content'])
                    ->usingFileName($attachment['filename'] !== '' ? $attachment['filename'] : 'attachment')
                    ->toMediaCollection('attachments');
            } catch (Throwable $e) {
                Log::warning('Incoming mail: failed to attach a file to the created issue.', [
                    'issue_id' => $issue->id,
                    'filename' => $attachment['filename'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $issue;
    }

    /**
     * Adds the mail body as a comment on an existing issue, matching
     * Redmine's MailHandler#receive_issue_reply. Any attachments on the
     * reply are added too, same as a fresh-issue mail. Gated by
     * edit_issues — the same permission the web edit form (which is
     * where the comment field lives) already requires, since this app
     * has no separate "add note" permission distinct from it.
     */
    private function receiveIssueReply(int $issueId, ParsedIncomingMail $mail, User $author): ?Issue
    {
        $issue = Issue::query()->find($issueId);

        if ($issue === null || ! $this->authorization->can($author, 'edit_issues', $issue->project)) {
            return null;
        }

        ['attributes' => $keywordAttributes, 'body' => $body] = $this->extractKeywordAttributes($mail->body, $issue->project, $author, $issue);
        $comment = trim($body);
        $updated = $this->issues->update($issue, $keywordAttributes, $author, $comment !== '' ? $comment : null);

        foreach ($mail->attachments as $attachment) {
            if ($attachment['content'] === '') {
                continue;
            }

            try {
                $updated->addMediaFromString($attachment['content'])
                    ->usingFileName($attachment['filename'] !== '' ? $attachment['filename'] : 'attachment')
                    ->toMediaCollection('attachments');
            } catch (Throwable $e) {
                Log::warning('Incoming mail: failed to attach a file to a reply comment.', [
                    'issue_id' => $updated->id,
                    'filename' => $attachment['filename'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $updated;
    }

    private function resolveProject(string $subject): ?Project
    {
        // Eager-loaded here rather than left to createIssueFromMail()'s
        // ->trackers access, since every caller of resolveProject() needs it.
        if (preg_match('/^\[([^\]]+)\]/', $subject, $matches) === 1) {
            $project = Project::query()->with('trackers')->where('identifier', $matches[1])->first();

            if ($project !== null) {
                return $project;
            }
        }

        $defaultProjectId = Setting::get('incoming_mail_default_project_id');

        return $defaultProjectId ? Project::with('trackers')->find($defaultProjectId) : null;
    }

    private function stripProjectPrefix(string $subject): string
    {
        return trim((string) preg_replace('/^\[([^\]]+)\]\s*/', '', $subject));
    }

    /**
     * Scans the body line by line for a recognized "Keyword: value" line
     * (see KEYWORD_ATTRIBUTES), resolving each value against the target
     * project and dropping the matched line from the returned body so it
     * isn't duplicated in the stored description/comment — matches
     * Redmine's destructive keyword-line removal in cleaned_up_text_body.
     * A keyword whose value can't be resolved (unknown status name, etc.)
     * is left as plain text in the body rather than silently vanishing,
     * so the sender can see what didn't take effect.
     *
     * @return array{attributes: array<string, int|string|float|bool>, body: string}
     */
    private function extractKeywordAttributes(string $body, Project $project, User $author, ?Issue $issue = null): array
    {
        $attributes = [];
        $kept = [];

        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^(status|priority|assigned to|done ratio|tracker|category|fixed version|start date|due date|estimated hours|private|parent issue)\s*:\s*(.+?)\s*$/i', $line, $matches) === 1) {
                $keyword = mb_strtolower($matches[1]);
                $value = $this->resolveKeywordValue($keyword, trim($matches[2]), $project, $author, $issue);

                if ($value !== null) {
                    $attributes[self::KEYWORD_ATTRIBUTES[$keyword]] = $value;

                    continue;
                }
            }

            $kept[] = $line;
        }

        return ['attributes' => $attributes, 'body' => trim(implode("\n", $kept))];
    }

    private function resolveKeywordValue(string $keyword, string $value, Project $project, User $author, ?Issue $issue = null): int|string|float|bool|null
    {
        if ($value === '') {
            return null;
        }

        if ($keyword === 'parent issue') {
            return $this->resolveParentIssueKeyword($value, $project, $issue);
        }

        if ($keyword === 'done ratio') {
            return is_numeric($value) && (int) $value >= 0 && (int) $value <= 100 ? (int) $value : null;
        }

        if ($keyword === 'estimated hours') {
            return is_numeric($value) && (float) $value >= 0 && (float) $value <= 9999.99 ? (float) $value : null;
        }

        if ($keyword === 'start date' || $keyword === 'due date') {
            // A strict round-trip through DateTime rather than Laravel's
            // 'date' validation rule, since there's no Validator instance
            // in play here — rejects both malformed strings and anything
            // DateTime would otherwise silently roll over (e.g. Feb 30).
            $date = \DateTime::createFromFormat('!Y-m-d', $value);

            return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
        }

        if ($keyword === 'private') {
            // Only honored when the sender actually holds
            // set_issues_private on the target project — same gate the
            // manual issue form applies before ever including is_private
            // in its own submitted data, and the same decision the CSV
            // importer already made for its own is_private column.
            if (! $this->authorization->can($author, 'set_issues_private', $project)) {
                return null;
            }

            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $id = match ($keyword) {
            'status' => IssueStatus::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->value('id'),
            'priority' => Enumeration::query()->ofType(EnumerationType::IssuePriority)->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->value('id'),
            'assigned to' => $project->assignableUsers()
                ->first(fn (User $user) => strcasecmp($user->email, $value) === 0 || strcasecmp($user->name, $value) === 0)?->id,
            // Scoped to the project's own trackers/categories/versions —
            // the same boundary the manual issue form enforces, so a
            // keyword line can't assign a tracker/category/version that
            // doesn't actually belong to this project.
            'tracker' => $project->trackers->first(fn (Tracker $tracker) => strcasecmp($tracker->name, $value) === 0)?->id,
            'category' => $project->issueCategories()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->value('id'),
            'fixed version' => $project->versions()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->value('id'),
            default => null,
        };

        return $id !== null ? (int) $id : null;
    }

    /**
     * Accepts a bare issue number or a "#123"-style reference, matching
     * how every other issue-id reference in this app's mail/commit
     * parsing is written. Scoped to the same project as the manual issue
     * form's own parent_id rule, and rejects self/descendant cycles via
     * Issue::descendantIds() the same way the form does (its own inline
     * ancestor-walk closure isn't reusable from a service class, so this
     * uses the already-shared descendantIds() the issue-relation
     * cycle/ancestor checks also rely on, rather than duplicating that
     * walk a third time). $issue is null when creating a brand new
     * issue, which can never already have descendants, so no cycle check
     * is needed there.
     */
    private function resolveParentIssueKeyword(string $value, Project $project, ?Issue $issue): ?int
    {
        $parentId = (int) preg_replace('/\D/', '', $value);

        if ($parentId === 0 || ($issue !== null && $parentId === $issue->id)) {
            return null;
        }

        $parent = Issue::query()->where('id', $parentId)->where('project_id', $project->id)->first();

        if ($parent === null) {
            return null;
        }

        if ($issue !== null && $issue->descendantIds()->contains($parent->id)) {
            return null;
        }

        return $parent->id;
    }
}
