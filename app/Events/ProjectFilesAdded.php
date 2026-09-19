<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Files were uploaded to a project or one of its versions (Redmine's
 * `file_added`).
 */
final class ProjectFilesAdded
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, string>  $fileNames
     */
    public function __construct(
        public readonly Project $project,
        public readonly User $actor,
        public readonly array $fileNames,
        public readonly ?string $versionName = null,
    ) {}
}
