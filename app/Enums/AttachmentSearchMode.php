<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Redmine's `attachments` search option: whether file names and
 * descriptions of attachments are searched too.
 */
enum AttachmentSearchMode: string
{
    /** Ignore attachments. */
    case Exclude = '0';

    /** Match the record's own text or any of its attachments. */
    case Include = '1';

    /** Match through attachments only. */
    case Only = 'only';

    /**
     * Redmine searches attachments in title-only mode only for `only`;
     * otherwise for every mode but `0`.
     */
    public function searchesAttachments(bool $titlesOnly): bool
    {
        return $titlesOnly ? $this === self::Only : $this !== self::Exclude;
    }

    public function searchesOwnText(): bool
    {
        return $this !== self::Only;
    }
}
