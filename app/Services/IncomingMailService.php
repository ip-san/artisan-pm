<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Setting;
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
 */
final class IncomingMailService
{
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
            $attachments[] = ['filename' => (string) $attachment->name, 'content' => (string) $attachment->content];
        }

        return new ParsedIncomingMail(
            subject: (string) $message->subject,
            body: $message->getTextBody(),
            fromEmail: (string) ($message->from[0]->mail ?? ''),
            attachments: $attachments,
        );
    }

    public function createIssueFromMail(ParsedIncomingMail $mail): ?Issue
    {
        $project = $this->resolveProject($mail->subject);

        if ($project === null) {
            return null;
        }

        $author = User::query()->where('email', $mail->fromEmail)->first();

        if ($author === null || ! $this->authorization->can($author, 'add_issues', $project)) {
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

        $issue = $this->issues->create([
            'project_id' => $project->id,
            'tracker_id' => $trackerId,
            'status_id' => $statusId,
            'priority_id' => $priorityId,
            'subject' => $subject !== '' ? $subject : '(no subject)',
            'description' => $mail->body,
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
}
