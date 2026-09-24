<?php

declare(strict_types=1);

namespace App\Mail;

use App\Concerns\FormatsDatesForRecipient;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Setting;
use App\Models\User;
use App\Support\Mail\EmailDecorations;
use App\Support\Mail\MessageIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Not queued itself — App\Notifications\NewsNotification (which builds
 * this) is the ShouldQueue boundary, matching Issue/WikiPage's same
 * convention.
 */
final class NewsNotificationMail extends Mailable
{
    use FormatsDatesForRecipient, Queueable, SerializesModels;

    public function __construct(
        public readonly News $news,
        public readonly string $eventType,
        public readonly User $actor,
        public readonly ?NewsComment $comment = null,
    ) {}

    /**
     * Redmine's threading headers: a news item is identified by itself, a
     * comment by the comment and referencing the news item.
     */
    public function headers(): Headers
    {
        $recipientId = $this->dateRecipient?->id;

        if ($this->comment !== null) {
            return new Headers(
                messageId: MessageIdentity::tokenFor($this->comment, $recipientId),
                references: [MessageIdentity::tokenFor($this->news, $recipientId)],
            );
        }

        return new Headers(messageId: MessageIdentity::tokenFor($this->news, $recipientId));
    }

    public function envelope(): Envelope
    {
        $fromAddress = Setting::get('mail_from');

        return new Envelope(
            subject: ($this->eventType === 'comment_added' ? 'Re: ' : '').__('[:project] お知らせ: :title', [
                'project' => $this->news->project->name,
                'title' => $this->news->title,
            ]),
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
            'header' => Setting::get('emails_header', ''),
            'footer' => Setting::get('emails_footer', ''),
            'headerHtml' => EmailDecorations::headerHtml(),
            'footerHtml' => EmailDecorations::footerHtml(),
            'url' => route('news.show', [$this->news->project, $this->news]),
        ];

        if ((bool) Setting::get('plain_text_mail', false)) {
            return new Content(text: 'mail.news.notification-text', with: $data);
        }

        return new Content(view: 'mail.news.notification', text: 'mail.news.notification-text', with: $data);
    }
}
