<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Mail\MessageIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Not queued itself (App\Notifications\IssueNotification, which builds
 * this, is the ShouldQueue boundary) — matches this app's existing
 * convention of putting ShouldQueue on the outer dispatch unit rather
 * than every Mailable.
 */
final class IssueNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Attribute keys from IssueService::JOURNALED_ATTRIBUTES that a
     * Journal detail's `attr` property can carry, mapped to a Japanese
     * label — the subset in Tracker::DISABLABLE_CORE_FIELDS plus the
     * always-on fields it excludes.
     *
     * @var array<string, string>
     */
    public const array ATTRIBUTE_LABELS = [
        'project_id' => 'プロジェクト',
        'tracker_id' => 'トラッカー',
        'status_id' => 'ステータス',
        'subject' => '題名',
        'is_private' => '非公開',
        ...Tracker::DISABLABLE_CORE_FIELDS,
    ];

    /**
     * A relation journal's prop_key is the relation as seen from this issue,
     * including the reversed names (blocked, duplicated, copied_from)
     * written on the receiving end.
     *
     * @var array<string, string>
     */
    public const array RELATION_LABELS = [
        'relates' => '関連',
        'blocks' => 'ブロックする',
        'blocked' => 'ブロックされている',
        'duplicates' => '重複する',
        'duplicated' => '重複されている',
        'precedes' => '先行',
        'follows' => '後続',
        'copied_to' => 'コピー先',
        'copied_from' => 'コピー元',
    ];

    public function __construct(
        public readonly Issue $issue,
        public readonly string $eventType,
        public readonly User $actor,
        public readonly ?Journal $journal = null,
        public readonly ?int $recipientId = null,
    ) {}

    /**
     * Redmine's threading headers: the creation mail is identified by the
     * issue, an update by its journal and referencing the issue.
     */
    public function headers(): Headers
    {
        if ($this->journal !== null) {
            return new Headers(
                messageId: MessageIdentity::tokenFor($this->journal, $this->recipientId),
                references: [MessageIdentity::tokenFor($this->issue, $this->recipientId)],
            );
        }

        return new Headers(messageId: MessageIdentity::tokenFor($this->issue, $this->recipientId));
    }

    public function envelope(): Envelope
    {
        $fromAddress = Setting::get('mail_from');

        return new Envelope(
            subject: sprintf(
                '[%s - %s #%d] %s%s',
                $this->issue->project->name,
                $this->issue->tracker->name,
                $this->issue->id,
                $this->statusInSubject() ? "({$this->issue->status->name}) " : '',
                $this->issue->subject,
            ),
            from: filled($fromAddress) ? new Address($fromAddress) : null,
        );
    }

    /**
     * Redmine's show_status_changes_in_mail_subject (default on): a new
     * issue's subject carries its status, an update's only when that update
     * changed the status (Mailer#issue_add / #issue_edit).
     */
    private function statusInSubject(): bool
    {
        if (! (bool) Setting::get('show_status_changes_in_mail_subject', true)) {
            return false;
        }

        if ($this->eventType === 'created') {
            return true;
        }

        return $this->journal?->details->contains(fn ($detail) => $detail->property === 'attr' && $detail->prop_key === 'status_id') ?? false;
    }

    public function content(): Content
    {
        $data = [
            'issue' => $this->issue,
            'eventType' => $this->eventType,
            'actor' => $this->actor,
            'journal' => $this->journal,
            'changes' => $this->changes(),
            'header' => Setting::get('emails_header', ''),
            'footer' => Setting::get('emails_footer', ''),
            'url' => route('issues.show', [$this->issue->project, $this->issue]),
        ];

        // Redmine's Setting.plain_text_mail forces a text/plain-only
        // message (no multipart HTML part) — a Content with no `view`
        // (only `text`) renders exactly that.
        if ((bool) Setting::get('plain_text_mail', false)) {
            return new Content(text: 'mail.issues.notification-text', with: $data);
        }

        return new Content(view: 'mail.issues.notification', text: 'mail.issues.notification-text', with: $data);
    }

    /**
     * Covers `attr`, `cf`, `attachment` and `relation` journal details — a
     * custom-field-only update still dispatches this mail
     * (IssueService::update()'s dispatch condition includes
     * $customFieldChanges !== []), so leaving cf rows out here would send a
     * "課題が更新されました" email with an empty change table whenever only a
     * custom field changed. An attachment row shows the file name on the
     * side where it exists; a relation row is labelled with the relation
     * type and shows the other issue as #id, like the journal on the issue
     * page.
     *
     * @return array<int, array{label: string, old: ?string, new: ?string}>
     */
    private function changes(): array
    {
        if ($this->journal === null) {
            return [];
        }

        $customFieldNames = CustomField::query()->pluck('name', 'id');

        return $this->journal->details
            ->whereIn('property', ['attr', 'cf', 'attachment', 'relation'])
            ->map(fn ($detail) => [
                'label' => match ($detail->property) {
                    'cf' => $customFieldNames[(int) $detail->prop_key] ?? $detail->prop_key,
                    'attachment' => '添付ファイル',
                    'relation' => self::RELATION_LABELS[$detail->prop_key] ?? $detail->prop_key,
                    default => self::ATTRIBUTE_LABELS[$detail->prop_key] ?? $detail->prop_key,
                },
                'old' => $detail->property === 'relation' && $detail->old_value !== null ? "#{$detail->old_value}" : $detail->old_value,
                'new' => $detail->property === 'relation' && $detail->new_value !== null ? "#{$detail->new_value}" : $detail->new_value,
            ])
            ->values()
            ->all();
    }
}
