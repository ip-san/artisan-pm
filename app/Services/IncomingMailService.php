<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnumerationType;
use App\Events\MessagePosted;
use App\Events\NewsCommentCreated;
use App\Models\Enumeration;
use App\Models\Issue;
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
use App\Support\Issues\StartDateDefault;
use App\Support\Mail\MailKeywordParser;
use App\Support\Mail\MailSuppression;
use App\Support\Mail\MessageIdentity;
use App\Support\Mail\ParsedIncomingMail;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
 * The body is also scanned for Redmine-style keyword command lines
 * ("Status: Closed") that set attributes and custom field values on the
 * created or replied-to issue — see MailKeywordParser.
 *
 * @phpstan-type HandlerOptions array{allow_override?: string, issue?: array<string, string>}
 */
final class IncomingMailService
{
    public function __construct(
        private readonly IssueService $issues,
        private readonly AuthorizationService $authorization,
        private readonly MailKeywordParser $keywords,
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
     * @param  HandlerOptions  $options
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
     *
     * @param  HandlerOptions  $options
     */
    public function createIssueFromMail(ParsedIncomingMail $mail, array $options = []): Issue|ForumMessage|NewsComment|null
    {
        // Redmine's no_notification: what a received mail creates or changes
        // is not announced by mail.
        return Setting::get('mail_handler_no_notification', false)
            ? MailSuppression::during(fn () => $this->handleMail($mail, $options))
            : $this->handleMail($mail, $options);
    }

    /**
     * @param  HandlerOptions  $options
     */
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
        ['attributes' => $keywordAttributes, 'body' => $body] = $this->keywords->extractKeywordAttributes($mail->body, $project, $author, null, $options);

        ['values' => $customFieldData, 'body' => $body] = $this->keywords->extractCustomFieldKeywords($body, $project, (int) ($keywordAttributes['tracker_id'] ?? $trackerId), $author, $options);

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
     *
     * @param  HandlerOptions  $options
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

        ['attributes' => $keywordAttributes, 'body' => $body] = $this->keywords->extractKeywordAttributes($mail->body, $issue->project, $author, $issue, $options);
        ['values' => $customFieldData, 'body' => $body] = $this->keywords->extractCustomFieldKeywords($body, $issue->project, (int) ($keywordAttributes['tracker_id'] ?? $issue->tracker_id), $author, $options);

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
     * Whether $subject matches $pattern used as a regular expression (Redmine's
     * enable_regex_* options). A pattern that is not a valid regular
     * expression matches nothing rather than failing the mail.
     */
    private static function matchesRegex(string $pattern, string $subject): bool
    {
        return @preg_match('~'.str_replace('~', '\\~', $pattern).'~u', $subject) === 1;
    }
}
