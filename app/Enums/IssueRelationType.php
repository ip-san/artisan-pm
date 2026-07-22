<?php

declare(strict_types=1);

namespace App\Enums;

enum IssueRelationType: string
{
    case Relates = 'relates';
    case Blocks = 'blocks';
    case Duplicates = 'duplicates';
    case Precedes = 'precedes';
    case Follows = 'follows';

    /**
     * The only direction ever stored — matches Redmine's IssueRelation,
     * which persists TYPE_COPIED_TO on the source→copy row and computes
     * "copied_from" purely as the reverse label when rendering from the
     * copy's own side (see issues/show.blade.php's RELATION_LABELS/
     * RELATION_JOURNAL_KEYS). There is no CopiedFrom case to store.
     */
    case CopiedTo = 'copied_to';
}
