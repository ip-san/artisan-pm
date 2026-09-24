<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use Stringable;

/**
 * An attachment custom field's value as screens show it (B'-02): its file
 * name wherever it is printed or joined (lists, CSV, PDF), and a download
 * link in <x-custom-field-value>.
 */
final readonly class AttachmentFieldFile implements Stringable
{
    public function __construct(
        public int $mediaId,
        public string $fileName,
    ) {}

    public function url(): string
    {
        return route('attachments.show', $this->mediaId);
    }

    public function __toString(): string
    {
        return $this->fileName;
    }
}
