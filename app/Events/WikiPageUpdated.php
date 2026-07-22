<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\WikiPage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class WikiPageUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WikiPage $wikiPage,
    ) {}
}
