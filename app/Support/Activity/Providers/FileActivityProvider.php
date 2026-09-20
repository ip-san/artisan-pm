<?php

declare(strict_types=1);

namespace App\Support\Activity\Providers;

use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Support\Activity\ActivityEntry;
use App\Support\Activity\ActivityProvider;
use App\Support\Activity\MultiProjectActivityProvider;
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
final class FileActivityProvider implements MultiProjectActivityProvider
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
        return $this->entriesForProjects(collect([$project]), $viewer, $from, $to);
    }

    public function entriesForProjects(Collection $projects, ?User $viewer, Carbon $from, Carbon $to): Collection
    {
        $projects = $projects->filter(fn (Project $project) => $this->authorization->can($viewer, 'view_files', $project))->keyBy('id');

        if ($projects->isEmpty()) {
            return collect();
        }

        $versionProjects = Version::query()->whereIn('project_id', $projects->keys())->pluck('project_id', 'id');

        $files = Media::query()
            ->where('collection_name', 'files')
            ->where(fn ($query) => $query
                ->where(fn ($own) => $own->where('model_type', 'project')->whereIn('model_id', $projects->keys()))
                ->orWhere(fn ($version) => $version->where('model_type', 'version')->whereIn('model_id', $versionProjects->keys())))
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $uploaders = User::query()
            ->whereIn('id', $files->map(fn (Media $media) => $media->getCustomProperty(AttachmentUploader::PROPERTY))->filter()->unique())
            ->get()
            ->keyBy('id');

        return $files->map(function (Media $media) use ($projects, $versionProjects, $uploaders) {
            $uploader = $uploaders->get($media->getCustomProperty(AttachmentUploader::PROPERTY));
            $project = $projects[$media->model_type === 'project' ? $media->model_id : $versionProjects[$media->model_id]];

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
