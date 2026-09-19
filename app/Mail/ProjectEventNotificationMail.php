<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Not queued itself — ProjectEventNotification is the ShouldQueue boundary,
 * like the other notification mails.
 */
final class ProjectEventNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $headline,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $body = null,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = Setting::get('mail_from');

        return new Envelope(
            subject: $this->subjectLine,
            from: filled($fromAddress) ? new Address($fromAddress) : null,
        );
    }

    public function content(): Content
    {
        $data = [
            'headline' => $this->headline,
            'title' => $this->title,
            'url' => $this->url,
            'body' => $this->body,
            'header' => Setting::get('emails_header', ''),
            'footer' => Setting::get('emails_footer', ''),
        ];

        if ((bool) Setting::get('plain_text_mail', false)) {
            return new Content(text: 'mail.project-event.notification-text', with: $data);
        }

        return new Content(view: 'mail.project-event.notification', text: 'mail.project-event.notification-text', with: $data);
    }
}
