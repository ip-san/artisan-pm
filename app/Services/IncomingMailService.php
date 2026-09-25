<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomizableType;
use App\Enums\EnumerationType;
use App\Events\MessagePosted;
use App\Events\NewsCommentCreated;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Message as ForumMessage;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Attachments\AttachmentUploader;
use App\Support\Authorization\AuthorizationService;
use App\Support\Format\Hours;
use App\Support\Issues\AssigneeChoice;
use App\Support\Issues\StartDateDefault;
use App\Support\Locale\SupportedLocales;
use App\Support\Mail\MailSuppression;
use App\Support\Mail\MessageIdentity;
use App\Support\Mail\ParsedIncomingMail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
 * start_date/due_date/estimated_hours/is_private/parent_issue. The label
 * itself is always accepted in fixed English (this app's own attribute
 * vocabulary — "parent issue" for example is chosen to match this app's
 * own attribute name rather than Redmine's English UI label "Parent
 * task", which none of this app's other keyword labels track literally
 * either, e.g. "done ratio" vs Redmine's "% Done") and, since A15-14,
 * additionally in Japanese (this app's own field labels, the same text
 * `issues/index.blade.php`'s `displayColumns()` uses) whenever the
 * sender's own `language` or the site's `default_language` is `ja` —
 * matching Redmine's `extract_keyword!`, which tries the English
 * `humanize`d name, the target user's own language, and
 * `Setting.default_language`, all at once. Custom-field keywords are
 * intentionally not translated — a custom field's name is free text set
 * by an administrator, not a translated label, so Redmine itself matches
 * it verbatim regardless of language.
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

    /**
     * KEYWORD_ATTRIBUTES's keyword => this app's own Japanese field label
     * for it (the same text `issues/index.blade.php::displayColumns()`
     * uses), accepted alongside the English keyword when the sender's or
     * site's language is Japanese (A15-14).
     *
     * @var array<string, string>
     */
    private const array KEYWORD_LABELS_JA = [
        'status' => 'ステータス',
        'priority' => '優先度',
        'assigned to' => '担当者',
        'done ratio' => '進捗率',
        'tracker' => 'トラッカー',
        'category' => 'カテゴリ',
        'fixed version' => '対象バージョン',
        'start date' => '開始日',
        'due date' => '期日',
        'estimated hours' => '予定工数',
        'private' => '非公開',
        'parent issue' => '親課題',
    ];

    /**
     * Redmine's `POST /mail_handler` `issue[...]` param names (matching
     * MailHandlerController's permitted params, minus `project` — this
     * app resolves the target project from the subject/recipient before
     * any keyword handling runs, not through this same fallback
     * mechanism) => this service's own internal keyword string.
     *
     * @var array<string, string>
     */
    private const array ISSUE_PARAM_TO_KEYWORD = [
        'status' => 'status',
        'tracker' => 'tracker',
        'category' => 'category',
        'priority' => 'priority',
        'assigned_to' => 'assigned to',
        'fixed_version' => 'fixed version',
        'is_private' => 'private',
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
     * $options carries the request's own `allow_override`/`issue[...]`
     * (Redmine's MailHandlerController#index permitted params, minus the
     * ones this app doesn't support — see MailHandlerController).
     *
     * @param  array{allow_override?: string, issue?: array<string, string>}  $options
     */
    public function processRawMessage(string $raw, array $options = []): Issue|ForumMessage|NewsComment|null
    {
        return $this->createIssueFromMail($this->parse(Message::fromString($raw)), $options);
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

    /**
     * Handles one parsed mail: a new issue, or a reply to an issue, a forum
     * topic or a news item. Null when nothing was recorded.
     */
    public function createIssueFromMail(ParsedIncomingMail $mail, array $options = []): Issue|ForumMessage|NewsComment|null
    {
        // Redmine's no_notification: what a received mail creates or changes
        // is not announced by mail.
        return Setting::get('mail_handler_no_notification', false)
            ? MailSuppression::during(fn () => $this->handleMail($mail, $options))
            : $this->handleMail($mail, $options);
    }

    private function handleMail(ParsedIncomingMail $mail, array $options = []): Issue|ForumMessage|NewsComment|null
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
            if ($target[0] === 'message') {
                return $this->receiveMessageReply($target[1], $mail, $author);
            }

            if ($target[0] === 'news' || $target[0] === 'comment') {
                $newsId = $target[0] === 'news' ? $target[1] : NewsComment::query()->whereKey($target[1])->value('news_id');

                return $newsId === null ? null : $this->receiveNewsReply((int) $newsId, $mail, $author);
            }

            $issueId = match ($target[0]) {
                'issue' => $target[1],
                'journal' => Journal::query()->whereKey($target[1])->value('issue_id'),
                default => null,
            };

            return $issueId === null ? null : $this->receiveIssueReply((int) $issueId, $mail, $author, $options);
        }

        if (preg_match('/\[(?:[^\]]*\s+)?#(\d+)\]/', $mail->subject, $matches) === 1) {
            return $this->receiveIssueReply((int) $matches[1], $mail, $author, $options);
        }

        // Redmine's MESSAGE_REPLY_SUBJECT_RE: "[Project - Board - msg12]".
        if (preg_match('/\[[^\]]*msg(\d+)\]/', $mail->subject, $matches) === 1) {
            return $this->receiveMessageReply((int) $matches[1], $mail, $author);
        }

        $project = $this->resolveProject($mail->subject, $mail->recipients);

        if ($project === null || ! $this->authorization->can($author, 'add_issues', $project)) {
            return null;
        }

        $trackerId = (int) Setting::get('incoming_mail_default_tracker_id', 0);

        // Redmine's allowed_target_trackers: the sender must be allowed to
        // create issues with the tracker (A1-27c).
        if (! Issue::allowedTargetTrackers($project, $author)->contains('id', $trackerId)) {
            return null;
        }

        $statusId = (int) Setting::get('incoming_mail_default_status_id', 0);
        $priorityId = Enumeration::query()->ofType(EnumerationType::IssuePriority)->where('is_default', true)->value('id');

        if ($statusId === 0 || $priorityId === null) {
            return null;
        }

        $subject = mb_substr($this->stripProjectPrefix($mail->subject), 0, 255);
        ['attributes' => $keywordAttributes, 'body' => $body] = $this->extractKeywordAttributes($mail->body, $project, $author, null, $options);

        ['values' => $customFieldData, 'body' => $body] = $this->extractCustomFieldKeywords($body, $project, (int) ($keywordAttributes['tracker_id'] ?? $trackerId), $author, $options);

        // Redmine's receive_issue: the keyword attributes and custom fields
        // go through the sender's workflow (read-only fields and those the
        // tracker disables are ignored), the subject and description are set
        // as they are, and a required field left blank rejects the mail.
        try {
            $issue = $this->issues->create([
                'project_id' => $project->id,
                'tracker_id' => $trackerId,
                'status_id' => $statusId,
                'priority_id' => $priorityId,
                'start_date' => StartDateDefault::forApiAndMail($author),
                ...$keywordAttributes,
            ], $author, $customFieldData, applyFieldRules: true, attributesOutsideFieldRules: [
                'subject' => $subject !== '' ? $subject : '(no subject)',
                'description' => $body,
            ]);
        } catch (ValidationException $exception) {
            Log::info('Incoming mail: the issue was not created.', ['from' => $mail->fromEmail, 'errors' => $exception->errors()]);

            return null;
        }

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
     * reply are added too, same as a fresh-issue mail. Gated like
     * Redmine: the sender must see the issue and hold add_issue_notes on
     * its tracker (edit_issues on it still works too, as before); keyword
     * attribute changes need the edit permission.
     */
    private function receiveIssueReply(int $issueId, ParsedIncomingMail $mail, User $author, array $options = []): ?Issue
    {
        $issue = Issue::query()->find($issueId);

        // Redmine's receive_issue_reply: the sender must see the issue and
        // may add notes on its tracker (notes_addable?, or edit it as this
        // app always allowed); keyword lines only change attributes for a
        // sender who may edit the issue.
        if ($issue === null || ! $issue->isVisibleTo($author)
            || (! Gate::forUser($author)->allows('addNotes', $issue) && ! Gate::forUser($author)->allows('update', $issue))) {
            return null;
        }

        ['attributes' => $keywordAttributes, 'body' => $body] = $this->extractKeywordAttributes($mail->body, $issue->project, $author, $issue, $options);
        ['values' => $customFieldData, 'body' => $body] = $this->extractCustomFieldKeywords($body, $issue->project, (int) ($keywordAttributes['tracker_id'] ?? $issue->tracker_id), $author, $options);

        if (! Gate::forUser($author)->allows('update', $issue)) {
            $keywordAttributes = [];
            $customFieldData = [];
        }
        $comment = trim($body);

        // A sender who may edit the issue goes through the workflow like the
        // issue form (read-only and disabled fields ignored, required ones
        // must stay filled — otherwise the reply is rejected, as Redmine's
        // save! fails); a notes-only reply is a comment.
        try {
            $updated = $this->issues->update($issue, $keywordAttributes, $author, $comment !== '' ? $comment : null, $customFieldData, applyFieldRules: Gate::forUser($author)->allows('update', $issue));
        } catch (ValidationException $exception) {
            Log::info('Incoming mail: the reply was not recorded.', ['issue_id' => $issue->id, 'errors' => $exception->errors()]);

            return null;
        }

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
     * Redmine's receive_message_reply: the body becomes a reply to the
     * topic (the root of the message replied to), unless the topic is
     * locked or the sender may not post in the project's forums.
     */
    private function receiveMessageReply(int $messageId, ParsedIncomingMail $mail, User $author): ?ForumMessage
    {
        $message = ForumMessage::query()->with(['board.project', 'parent'])->find($messageId);
        $topic = $message === null ? null : ($message->parent ?? $message);

        if ($topic === null || ! Gate::forUser($author)->allows('view', $topic) || ! Gate::forUser($author)->allows('reply', $topic)) {
            return null;
        }

        $content = trim($mail->body);

        if ($content === '') {
            return null;
        }

        $subject = trim((string) preg_replace('/^.*msg\d+\]/', '', $mail->subject));
        $subject = trim((string) preg_replace('/^(re|fwd?)\s*:\s*/i', '', $subject));

        $reply = ForumMessage::create([
            'board_id' => $topic->board_id,
            'parent_id' => $topic->id,
            'author_id' => $author->id,
            'subject' => mb_substr($subject !== '' ? $subject : "RE: {$topic->subject}", 0, 255),
            'content' => $content,
        ]);

        $this->attachFiles($reply, $mail, $author);
        MessagePosted::dispatch($reply);
        $topic->touch();

        return $reply;
    }

    /**
     * Redmine's receive_news_reply: the body becomes a comment on the news
     * item when the sender may comment on it.
     */
    private function receiveNewsReply(int $newsId, ParsedIncomingMail $mail, User $author): ?NewsComment
    {
        $news = News::query()->with('project')->find($newsId);

        if ($news === null || ! Gate::forUser($author)->allows('view', $news) || ! Gate::forUser($author)->allows('comment', $news)) {
            return null;
        }

        $content = trim($mail->body);

        if ($content === '') {
            return null;
        }

        $comment = NewsComment::create([
            'news_id' => $news->id,
            'author_id' => $author->id,
            'content' => $content,
        ]);

        NewsCommentCreated::dispatch($comment);

        return $comment;
    }

    /**
     * Adds the mail's attachments to a forum post, one at a time so a file
     * that fails does not lose the others.
     */
    private function attachFiles(ForumMessage $record, ParsedIncomingMail $mail, User $author): void
    {
        foreach ($mail->attachments as $attachment) {
            if ($attachment['content'] === '') {
                continue;
            }

            try {
                $record->addMediaFromString($attachment['content'])
                    ->usingFileName($attachment['filename'] !== '' ? $attachment['filename'] : 'attachment')
                    ->withCustomProperties([AttachmentUploader::PROPERTY => $author->id])
                    ->toMediaCollection('attachments');
            } catch (Throwable $e) {
                Log::warning('Incoming mail: failed to attach a file to a forum reply.', [
                    'message_id' => $record->id,
                    'filename' => $attachment['filename'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
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
    private function extractKeywordAttributes(string $body, Project $project, User $author, ?Issue $issue = null, array $options = []): array
    {
        $attributes = [];
        $kept = [];

        $allowed = $this->overridableKeywords($options);
        [$pattern, $labelToKeyword] = $this->keywordPattern($author);

        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^('.$pattern.')\s*:\s*(.+?)\s*$/iu', $line, $matches) === 1) {
                $keyword = $labelToKeyword[mb_strtolower($matches[1])] ?? null;

                if ($keyword !== null && $this->keywordAllowed($keyword, $allowed)
                    && $this->assignKeywordValue($attributes, $keyword, trim($matches[2]), $project, $author, $issue)) {
                    continue;
                }
            }

            $kept[] = $line;
        }

        // Redmine's get_keyword: a request-supplied issue[...] default fills
        // an attribute the body didn't (whether because no line matched, or
        // because that keyword isn't in allow_override) — applied
        // regardless of override permission, same as Redmine.
        foreach (self::ISSUE_PARAM_TO_KEYWORD as $param => $keyword) {
            $raw = $options['issue'][$param] ?? null;

            if (! is_string($raw) || $raw === '' || array_key_exists(self::KEYWORD_ATTRIBUTES[$keyword], $attributes)) {
                continue;
            }

            if ($keyword === 'assigned to' && array_key_exists('assigned_to_id', $attributes)) {
                continue;
            }

            $this->assignKeywordValue($attributes, $keyword, $raw, $project, $author, $issue);
        }

        return ['attributes' => $attributes, 'body' => trim(implode("\n", $kept))];
    }

    /**
     * Resolves one keyword's raw text and, if it resolves to something,
     * merges it into $attributes (an assignee resolves to one or more
     * Issue attributes via AssigneeChoice::decode(), everything else to a
     * single one via KEYWORD_ATTRIBUTES). Returns whether a value was set.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assignKeywordValue(array &$attributes, string $keyword, string $rawValue, Project $project, User $author, ?Issue $issue): bool
    {
        $value = $this->resolveKeywordValue($keyword, $rawValue, $project, $author, $issue);

        if ($value === null) {
            return false;
        }

        if ($keyword === 'assigned to') {
            // A user id, or `group:<id>` for a group assignee.
            $attributes = [...$attributes, ...AssigneeChoice::decode((string) $value)];

            return true;
        }

        $attributes[self::KEYWORD_ATTRIBUTES[$keyword]] = $value;

        return true;
    }

    /**
     * The keyword-line regex alternation and its label => keyword lookup
     * (lowercased, so a plain array lookup on mb_strtolower($match) works
     * uniformly for the case-insensitive English labels and the
     * case-less Japanese ones): the English label always, plus the
     * Japanese one for every keyword whose sender/site language is
     * Japanese (A15-14, matching Redmine's extract_keyword!).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function keywordPattern(User $author): array
    {
        $labelToKeyword = [];

        foreach (array_keys(self::KEYWORD_ATTRIBUTES) as $keyword) {
            $labelToKeyword[$keyword] = $keyword;
        }

        if ($this->acceptsJapaneseKeywords($author)) {
            foreach (self::KEYWORD_LABELS_JA as $keyword => $label) {
                $labelToKeyword[$label] = $keyword;
            }
        }

        $pattern = implode('|', array_map(fn (string $label) => preg_quote($label, '/'), array_keys($labelToKeyword)));

        return [$pattern, $labelToKeyword];
    }

    /**
     * Whether Japanese keyword labels should also be accepted: the
     * sender's own language is Japanese, or (Redmine always also tries
     * Setting.default_language, regardless of the sender's own) the
     * site's default language is.
     */
    private function acceptsJapaneseKeywords(User $author): bool
    {
        return $author->language === 'ja' || SupportedLocales::default() === 'ja';
    }

    /**
     * Scans the body for "Custom field name: value" lines (Redmine's
     * custom_field_values_from_keywords). Like the built-in keywords a line
     * counts only when mail_handler_allow_override names the field (its name
     * lowercased, spaces as underscores) or is `all` — Redmine's get_keyword
     * — and only for a field the sender could fill in on the form: one of the issue's tracker and project, visible to the sender's
     * roles, and editable. A value the field would not accept (not one of a
     * list's options, not a number, ...) leaves its line in the body.
     *
     * @return array{values: array<int, mixed>, body: string}
     */
    private function extractCustomFieldKeywords(string $body, Project $project, int $trackerId, User $author, array $options = []): array
    {
        $allowed = $this->overridableKeywords($options);
        $fields = $this->keywordCustomFields($project, $trackerId, $author)
            ->filter(fn (CustomField $field) => in_array('all', $allowed, true)
                || in_array(preg_replace('/\s+/', '_', mb_strtolower(trim($field->name))), $allowed, true))
            ->values();

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
        $parts = $field->multiple ? array_values(array_filter(array_map('trim', explode(',', $text)), fn (string $part) => $part !== '')) : [$text];
        $resolved = [];

        foreach ($parts as $part) {
            $value = $field->valueFromKeyword($part, $project) ?? '';

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
     * "Keyword: value" line — none by default (Redmine's empty option), `all`,
     * or a comma-separated list such as "status, priority, assigned_to";
     * matching ignores case and treats spaces as underscores.
     *
     * In real Redmine this is never a persisted site setting — it's a
     * per-invocation parameter only (the fetchmail rake task's own
     * `allow_override=` argument, or `POST /mail_handler`'s own
     * `allow_override` param; MailHandler#get_keyword reads only
     * handler_options[:allow_override], never Setting). This app instead
     * persists a site-wide `mail_handler_allow_override` setting, since
     * the scheduled mailbox fetch (fetchAndProcess()) has no per-request
     * value to draw one from. `POST /mail_handler`'s own
     * `allow_override` param, when given, is used for that request
     * instead of the site setting (A15-14) — matching Redmine for the one
     * path that actually has a per-request value to offer.
     *
     * @param  array{allow_override?: string}  $options
     * @return array<int, string>
     */
    private function overridableKeywords(array $options = []): array
    {
        $raw = array_key_exists('allow_override', $options) ? (string) $options['allow_override'] : (string) Setting::get('mail_handler_allow_override', '');

        return collect(explode(',', $raw))
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
            return $this->resolveParentIssueKeyword($value, $project, $author, $issue);
        }

        if ($keyword === 'done ratio') {
            return is_numeric($value) && (int) $value >= 0 && (int) $value <= 100 ? (int) $value : null;
        }

        if ($keyword === 'estimated hours') {
            $hours = Hours::parse($value);

            return $hours !== null && $hours >= 0 && $hours <= 9999.99 ? round($hours, 2) : null;
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

        // Redmine's find_assignee_from_keyword: an assignable user by mail or
        // name, else (issue_group_assignment on) an assignable group by name.
        if ($keyword === 'assigned to') {
            $userId = $project->assignableUsers()
                ->first(fn (User $user) => strcasecmp($user->email, $value) === 0 || strcasecmp($user->name, $value) === 0)?->id;

            if ($userId !== null) {
                return (int) $userId;
            }

            $group = Issue::groupAssignmentEnabled()
                ? $project->assignableGroups()->first(fn (Group $group) => strcasecmp($group->name, $value) === 0)
                : null;

            return $group !== null ? AssigneeChoice::forGroup($group) : null;
        }

        $id = match ($keyword) {
            'status' => IssueStatus::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->value('id'),
            'priority' => Enumeration::query()->ofType(EnumerationType::IssuePriority)->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->value('id'),
            // Scoped to the project's own trackers/categories/versions —
            // the same boundary the manual issue form enforces, so a
            // keyword line can't assign a tracker/category/version that
            // doesn't actually belong to this project.
            // Only a tracker the sender may use (Redmine's
            // allowed_target_trackers, plus the replied issue's own).
            'tracker' => Issue::allowedTargetTrackers($project, $author, $issue?->tracker_id)->first(fn (Tracker $tracker) => strcasecmp($tracker->name, $value) === 0)?->id,
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
    private function resolveParentIssueKeyword(string $value, Project $project, User $author, ?Issue $issue): ?int
    {
        $parentId = (int) preg_replace('/\D/', '', $value);

        if ($parentId === 0 || ($issue !== null && $parentId === $issue->id)) {
            return null;
        }

        $parent = Issue::query()->where('id', $parentId)->where('project_id', $project->id)->visibleTo($author, $project)->first();

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
