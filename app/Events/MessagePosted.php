<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A forum topic or reply was posted (Redmine's `message_posted`).
 */
final class MessagePosted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Message $message,
    ) {}
}
