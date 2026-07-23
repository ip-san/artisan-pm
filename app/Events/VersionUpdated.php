<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Version;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class VersionUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Version $version,
    ) {}
}
