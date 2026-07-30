<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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
    private const array ATTRIBUTE_LABELS = [
        'project_id' => 'プロジェクト',
        'tracker_id' => 'トラッカー',
        'status_id' => 'ステータス',
        'subject' => '題名',
        'is_private' => '非公開',
        ...Tracker::DISABLABLE_CORE_FIELDS,
    ];

    public function __construct(
        public readonly Issue $issue,
        public readonly string $eventType,
        public readonly User $actor,
        public readonly ?Journal $journal = null,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = Setting::get('mail_from');

        return new Envelope(
            subject: sprintf(
                '[%s - %s #%d] (%s) %s',
                $this->issue->project->name,
                $this->issue->tracker->name,
                $this->issue->id,
                $this->issue->status->name,
                $this->issue->subject,
            ),
            from: filled($fromAddress) ? new Address($fromAddress) : null,
        );
    }

    public function content(): Content
    {
        $data = [
            'issue' => $this->issue,
            'eventType' => $this->eventType,
            'actor' => $this->actor,
            'journal' => $this->journal,
            'changes' => $this->changes(),
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
     * Covers both `attr` and `cf` journal details — a custom-field-only
     * update still dispatches this mail (IssueService::update()'s
     * dispatch condition includes $customFieldChanges !== []), so leaving
     * cf rows out here would send a "課題が更新されました" email with an
     * empty change table whenever only a custom field changed.
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
            ->whereIn('property', ['attr', 'cf'])
            ->map(fn ($detail) => [
                'label' => $detail->property === 'cf'
                    ? ($customFieldNames[(int) $detail->prop_key] ?? $detail->prop_key)
                    : (self::ATTRIBUTE_LABELS[$detail->prop_key] ?? $detail->prop_key),
                'old' => $detail->old_value,
                'new' => $detail->new_value,
            ])
            ->values()
            ->all();
    }
}
