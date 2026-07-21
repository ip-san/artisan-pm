<?php

declare(strict_types=1);

namespace App\Enums;

enum QueryType: string
{
    case Issue = 'issue';
    case TimeEntry = 'time_entry';
}
