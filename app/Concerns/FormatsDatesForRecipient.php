<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use App\Support\Format\DateTimes;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\SentMessage;

/**
 * A mail shows dates and times in its recipient's zone, the way Laravel
 * builds a notification in its recipient's language (User::preferredLocale()):
 * whoever triggered the send is not the one reading it.
 */
trait FormatsDatesForRecipient
{
    public ?User $dateRecipient = null;

    public function forRecipient(object $notifiable): static
    {
        $this->dateRecipient = $notifiable instanceof User ? $notifiable : null;

        return $this;
    }

    /**
     * @param  MailFactory|Mailer  $mailer
     */
    public function send($mailer): ?SentMessage
    {
        return DateTimes::asViewer($this->dateRecipient, fn () => parent::send($mailer));
    }

    public function render(): string
    {
        return DateTimes::asViewer($this->dateRecipient, fn () => parent::render());
    }
}
