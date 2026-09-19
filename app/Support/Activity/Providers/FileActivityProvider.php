<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\ActivityProvider;
use App\Support\Attachments\AttachmentUploader;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Redmine's `files` activity: a file added on the Files tab, to the project
 * itself or to one of its versions. Attributed to whoever uploaded it when
 * that is known (files uploaded before uploaders were recorded have no
 * author).
 */
final class FileActivityProvider implements ActivityProvider
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function type(): string
    {
        return 'file';
    }

    public function label(): string
    {
        return 'ファイル';
    }

    public function entries(Project $project, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        if (! $this->authorization->can($viewer, 'view_files', $project)) {
            return collect();
        }

        $versionIds = Version::query()->where('project_id', $project->id)->pluck('id');

        $files = Media::query()
            ->where('collection_name', 'files')
            ->where(fn ($query) => $query
                ->where(fn ($own) => $own->where('model_type', 'project')->where('model_id', $project->id))
                ->orWhere(fn ($version) => $version->where('model_type', 'version')->whereIn('model_id', $versionIds)))
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $uploaders = User::query()
            ->whereIn('id', $files->map(fn (Media $media) => $media->getCustomProperty(AttachmentUploader::PROPERTY))->filter()->unique())
            ->get()
            ->keyBy('id');

        return $files->map(function (Media $media) use ($project, $uploaders) {
            $uploader = $uploaders->get($media->getCustomProperty(AttachmentUploader::PROPERTY));

            return new ActivityEntry(
                type: $this->type(),
                title: $media->file_name,
                url: route('files.index', $project),
                authorName: $uploader?->name,
                occurredAt: $media->created_at,
                authorId: $uploader?->id,
            );
        });
    }
}
