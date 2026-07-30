<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\News;
use App\Models\NewsComment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Not queued itself — App\Notifications\NewsNotification (which builds
 * this) is the ShouldQueue boundary, matching Issue/WikiPage's same
 * convention.
 */
final class NewsNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly News $news,
        public readonly string $eventType,
        public readonly User $actor,
        public readonly ?NewsComment $comment = null,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = Setting::get('mail_from');

        return new Envelope(
            subject: sprintf(
                '%s[%s] お知らせ: %s',
                $this->eventType === 'comment_added' ? 'Re: ' : '',
                $this->news->project->name,
                $this->news->title,
            ),
            from: filled($fromAddress) ? new Address($fromAddress) : null,
        );
    }

    public function content(): Content
    {
        $data = [
            'news' => $this->news,
            'eventType' => $this->eventType,
            'actor' => $this->actor,
            'comment' => $this->comment,
            'footer' => Setting::get('emails_footer', ''),
            'url' => route('news.show', [$this->news->project, $this->news]),
        ];

        if ((bool) Setting::get('plain_text_mail', false)) {
            return new Content(text: 'mail.news.notification-text', with: $data);
        }

        return new Content(view: 'mail.news.notification', text: 'mail.news.notification-text', with: $data);
    }
}
