<?php

declare(strict_types=1);

namespace App\Enums;

enum VersionStatus: string
{
    case Open = 'open';
    case Locked = 'locked';
    case Closed = 'closed';
}
