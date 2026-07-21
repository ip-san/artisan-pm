<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
    case Archived = 'archived';
}
