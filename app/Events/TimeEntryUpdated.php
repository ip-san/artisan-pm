<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\TimeEntry;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class TimeEntryUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TimeEntry $timeEntry,
    ) {}
}
