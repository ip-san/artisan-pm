<?php

namespace App\Enums;

enum IssueRelationType: string
{
    case Relates = 'relates';
    case Blocks = 'blocks';
    case Duplicates = 'duplicates';
    case Precedes = 'precedes';
    case Follows = 'follows';
}
