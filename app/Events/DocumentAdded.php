<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A document was added to a project (Redmine's `document_added`).
 */
final class DocumentAdded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly User $actor,
    ) {}
}
