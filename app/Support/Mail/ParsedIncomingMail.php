<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * The subset of an inbound email IncomingMailService actually needs, kept
 * separate from Webklex\PHPIMAP\Message so the issue-creation logic is
 * testable without a real IMAP connection.
 */
final readonly class ParsedIncomingMail
{
    /**
     * @param  array<int, array{filename: string, content: string}>  $attachments
     */
    public function __construct(
        public string $subject,
        public string $body,
        public string $fromEmail,
        public array $attachments = [],
    ) {}
}
