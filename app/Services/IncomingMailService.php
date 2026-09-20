<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Journal;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Attachments\AttachmentUploader;
use App\Support\Authorization\AuthorizationService;
use App\Support\Issues\StartDateDefault;
use App\Support\Mail\MailSuppression;
use App\Support\Mail\MessageIdentity;
use App\Support\Mail\ParsedIncomingMail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
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

    /**
     * Handles one raw RFC 822 message (what the mail_handler web service
     * receives) exactly as a mail fetched from the mailbox would be.
     */
    public function processRawMessage(string $raw): ?Issue
    {
        return $this->createIssueFromMail($this->parse(Message::fromString($raw)));
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
            replyHeaders: array_map('strval', [...$message->in_reply_to->all(), ...$message->references->all()]),
            recipients: array_values(array_filter(array_map(fn ($address) => (string) ($address->mail ?? ''), [...$message->to->all(), ...$message->cc->all()]))),
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
        $asRegex = (bool) Setting::get('mail_handler_enable_regex_delimiters', false);

        foreach ($lines as $index => $line) {
            $matches = $asRegex
                ? collect($delimiters)->contains(fn (string $pattern) => self::matchesRegex($pattern, trim($line)))
                : in_array(trim($line), $delimiters, true);

            if ($matches) {
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

        if (Setting::get('mail_handler_enable_regex_excluded_filenames', false)) {
            return $patterns->contains(fn (string $pattern) => self::matchesRegex($pattern, $filename));
        }

        return $patterns->contains(fn (string $pattern) => fnmatch($pattern, $filename, FNM_CASEFOLD));
    }

    public function createIssueFromMail(ParsedIncomingMail $mail): ?Issue
    {
        // Redmine's no_notification: what a received mail creates or changes
        // is not announced by mail.
        return Setting::get('mail_handler_no_notification', false)
            ? MailSuppression::during(fn () => $this->handleMail($mail))
            : $this->handleMail($mail);
    }

    private function handleMail(ParsedIncomingMail $mail): ?Issue
    {
        // The sender may write from the primary address or an additional one.
        $author = User::query()->where('email', $mail->fromEmail)->first()
            ?? User::query()->whereHas('additionalEmails', fn ($query) => $query->whereRaw('lower(address) = ?', [mb_strtolower($mail->fromEmail)]))->first();

        if ($author === null) {
            return null;
        }

        // Redmine's MailHandler#dispatch: a reply is recognised first by the
        // Message-Id our notification mails carry (In-Reply-To / References),
        // then by a "[... #123]" subject. A recognised header for something
        // this app has no reply handler for is ignored, not treated as a new
        // issue.
        $target = MessageIdentity::target($mail->replyHeaders);

        if ($target !== null) {
            $issueId = match ($target[0]) {
                'issue' => $target[1],
                'journal' => Journal::query()->whereKey($target[1])->value('issue_id'),
                default => null,
            };

            return $issueId === null ? null : $this->receiveIssueReply((int) $issueId, $mail, $author);
        }

        if (preg_match('/\[(?:[^\]]*\s+)?#(\d+)\]/', $mail->subject, $matches) === 1) {
            return $this->receiveIssueReply((int) $matches[1], $mail, $author);
        }

        $project = $this->resolveProject($mail->subject, $mail->recipients);

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

        ['values' => $customFieldData, 'body' => $body] = $this->extractCustomFieldKeywords($body, $project, (int) ($keywordAttributes['tracker_id'] ?? $trackerId), $author);

        $issue = $this->issues->create([
            'project_id' => $project->id,
            'tracker_id' => $trackerId,
            'status_id' => $statusId,
            'priority_id' => $priorityId,
            'subject' => $subject !== '' ? $subject : '(no subject)',
            'description' => $body,
            'start_date' => StartDateDefault::forApiAndMail(),
            ...$keywordAttributes,
        ], $author, $customFieldData);

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
                    ->withCustomProperties([AttachmentUploader::PROPERTY => $issue->author_id])
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
        ['values' => $customFieldData, 'body' => $body] = $this->extractCustomFieldKeywords($body, $issue->project, (int) ($keywordAttributes['tracker_id'] ?? $issue->tracker_id), $author);
        $comment = trim($body);
        $updated = $this->issues->update($issue, $keywordAttributes, $author, $comment !== '' ? $comment : null, $customFieldData);

        foreach ($mail->attachments as $attachment) {
            if ($attachment['content'] === '') {
                continue;
            }

            try {
                $updated->addMediaFromString($attachment['content'])
                    ->usingFileName($attachment['filename'] !== '' ? $attachment['filename'] : 'attachment')
                    ->withCustomProperties([AttachmentUploader::PROPERTY => $author->id])
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

    /**
     * @param  array<int, string>  $recipients
     */
    private function resolveProject(string $subject, array $recipients = []): ?Project
    {
        // Eager-loaded here rather than left to createIssueFromMail()'s
        // ->trackers access, since every caller of resolveProject() needs it.
        $identifier = $this->identifierFromSubaddress($recipients);

        if ($identifier !== null) {
            $project = Project::query()->with('trackers')->where('identifier', $identifier)->first();

            if ($project !== null) {
                return $project;
            }
        }

        if (preg_match('/^\[([^\]]+)\]/', $subject, $matches) === 1) {
            $project = Project::query()->with('trackers')->where('identifier', $matches[1])->first();

            if ($project !== null) {
                return $project;
            }
        }

        $defaultProjectId = Setting::get('incoming_mail_default_project_id');

        return $defaultProjectId ? Project::with('trackers')->find($defaultProjectId) : null;
    }

    /**
     * Redmine's project_from_subaddress: with the setting at
     * "redmine@example.net", a mail sent to "redmine+support@example.net"
     * targets the project whose identifier is "support" (the To and Cc
     * addresses are checked in order).
     *
     * @param  array<int, string>  $recipients
     */
    private function identifierFromSubaddress(array $recipients): ?string
    {
        $configured = mb_strtolower(trim((string) Setting::get('mail_handler_project_from_subaddress', '')));

        if (! str_contains($configured, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $configured, 2);

        foreach ($recipients as $recipient) {
            $recipient = mb_strtolower(trim($recipient));

            if (preg_match('/^'.preg_quote($local, '/').'\+([^@]+)@'.preg_quote($domain, '/').'$/', $recipient, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
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

        $allowed = $this->overridableKeywords();

        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^(status|priority|assigned to|done ratio|tracker|category|fixed version|start date|due date|estimated hours|private|parent issue)\s*:\s*(.+?)\s*$/i', $line, $matches) === 1
                && $this->keywordAllowed(mb_strtolower($matches[1]), $allowed)) {
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

    /**
     * Scans the body for "Custom field name: value" lines (Redmine's
     * custom_field_values_from_keywords). Unlike the built-in keywords these
     * are always honored, but only for a field the sender could fill in on the
     * form: one of the issue's tracker and project, visible to the sender's
     * roles, and editable. A value the field would not accept (not one of a
     * list's options, not a number, ...) leaves its line in the body.
     *
     * @return array{values: array<int, mixed>, body: string}
     */
    private function extractCustomFieldKeywords(string $body, Project $project, int $trackerId, User $author): array
    {
        $fields = $this->keywordCustomFields($project, $trackerId, $author);

        if ($fields->isEmpty()) {
            return ['values' => [], 'body' => $body];
        }

        $values = [];
        $kept = [];

        foreach (explode("\n", $body) as $line) {
            $matched = false;

            foreach ($fields as $field) {
                if (! array_key_exists($field->id, $values)
                    && preg_match('/^'.preg_quote($field->name, '/').'[ \t]*:[ \t]*(.+?)\s*$/iu', $line, $matches) === 1) {
                    $value = $this->customFieldValueFromKeyword($field, trim($matches[1]), $project);

                    if ($value !== null) {
                        $values[$field->id] = $value;
                        $matched = true;

                        break;
                    }
                }
            }

            if (! $matched) {
                $kept[] = $line;
            }
        }

        return ['values' => $values, 'body' => trim(implode("\n", $kept))];
    }

    /**
     * @return Collection<int, CustomField>
     */
    private function keywordCustomFields(Project $project, int $trackerId, User $author): Collection
    {
        $roles = $author->is_admin ? collect() : $this->authorization->rolesFor($author, $project);

        return CustomField::query()
            ->where('customized_type', CustomizableType::Issue)
            ->whereHas('trackers', fn ($query) => $query->where('trackers.id', $trackerId))
            ->with(['projects', 'roles'])
            ->orderBy('position')
            ->get()
            ->filter(fn (CustomField $field) => $field->appliesToProject($project)
                && ($author->is_admin || $field->visibleToRoles($roles))
                && $field->editableBy($author))
            ->values();
    }

    /**
     * The stored value for what a "Field: value" line says, or null when the
     * field would not take it. A field with fixed options is matched by its
     * label (a multiple one takes a comma-separated list); a yes/no field
     * takes yes/no/1/0/true/false.
     *
     * @return string|array<int, string>|null
     */
    private function customFieldValueFromKeyword(CustomField $field, string $text, Project $project): string|array|null
    {
        $options = $field->optionsFor($project);
        $parts = $field->multiple ? array_values(array_filter(array_map('trim', explode(',', $text)), fn (string $part) => $part !== '')) : [$text];
        $resolved = [];

        foreach ($parts as $part) {
            $value = match (true) {
                $options !== [] => (string) (array_search(mb_strtolower($part), array_map('mb_strtolower', $options), true) ?: ''),
                $field->field_format === \App\Enums\CustomFieldFormat::Bool => match (mb_strtolower($part)) {
                    '1', 'yes', 'true' => '1',
                    '0', 'no', 'false' => '0',
                    default => '',
                },
                default => $part,
            };

            $valid = $value !== '' && Validator::make(['value' => $value], ['value' => $field->validationRulesFor($project)])->passes();

            if (! $valid) {
                return null;
            }

            $resolved[] = $value;
        }

        if ($resolved === []) {
            return null;
        }

        return $field->multiple ? $resolved : $resolved[0];
    }

    /**
     * Redmine's allow_override: the attributes a sender may set with a
     * "Keyword: value" line. `all` (the default here, which keeps every
     * keyword working as it did) or a comma-separated list such as
     * "status, priority, assigned_to"; matching ignores case and treats
     * spaces as underscores.
     *
     * @return array<int, string>
     */
    private function overridableKeywords(): array
    {
        return collect(explode(',', (string) Setting::get('mail_handler_allow_override', 'all')))
            ->map(fn (string $name) => preg_replace('/\s+/', '_', mb_strtolower(trim($name))))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function keywordAllowed(string $keyword, array $allowed): bool
    {
        $name = str_replace(' ', '_', $keyword);

        return in_array('all', $allowed, true)
            || in_array($name, $allowed, true)
            || ($keyword === 'private' && in_array('is_private', $allowed, true));
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

    /**
     * Whether $subject matches $pattern used as a regular expression (Redmine's
     * enable_regex_* options). A pattern that is not a valid regular
     * expression matches nothing rather than failing the mail.
     */
    private static function matchesRegex(string $pattern, string $subject): bool
    {
        return @preg_match('~'.str_replace('~', '\\~', $pattern).'~u', $subject) === 1;
    }
}
