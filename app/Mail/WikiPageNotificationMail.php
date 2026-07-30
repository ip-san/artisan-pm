<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Setting;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Not queued itself — App\Notifications\WikiPageNotification (which
 * builds this) is the ShouldQueue boundary, matching
 * IssueNotificationMail's same convention.
 */
final class WikiPageNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly WikiPage $wikiPage,
        public readonly string $eventType,
        public readonly User $actor,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = Setting::get('mail_from');

        return new Envelope(
            subject: sprintf(
                '[%s] Wikiページ「%s」が%s',
                $this->wikiPage->project->name,
                $this->wikiPage->title,
                $this->eventType === 'created' ? '追加されました' : '更新されました',
            ),
            from: filled($fromAddress) ? new Address($fromAddress) : null,
        );
    }

    public function content(): Content
    {
        $data = [
            'wikiPage' => $this->wikiPage,
            'eventType' => $this->eventType,
            'actor' => $this->actor,
            'footer' => Setting::get('emails_footer', ''),
            'url' => route('wiki.show', [$this->wikiPage->project, $this->wikiPage]),
        ];

        if ((bool) Setting::get('plain_text_mail', false)) {
            return new Content(text: 'mail.wiki-pages.notification-text', with: $data);
        }

        return new Content(view: 'mail.wiki-pages.notification', text: 'mail.wiki-pages.notification-text', with: $data);
    }
}
