<?php

namespace App\Enums;

enum VersionStatus: string
{
    case Open = 'open';
    case Locked = 'locked';
    case Closed = 'closed';
}
