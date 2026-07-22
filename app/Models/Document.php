<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasCustomFields;
use App\Concerns\HasThumbnails;
use App\Enums\CustomizableType;
use App\Support\Authorization\AuthorizationService;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['project_id', 'category_id', 'title', 'description'])]
final class Document extends Model implements HasMedia
{
    /** @use HasFactory<DocumentFactory> */
    use HasCustomFields, HasFactory, HasThumbnails, InteractsWithMedia, Searchable {
        HasThumbnails::registerMediaConversions insteadof InteractsWithMedia;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Enumeration, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Enumeration::class, 'category_id');
    }

    public static function customizableType(): CustomizableType
    {
        return CustomizableType::Document;
    }

    /**
     * Documents have no roles/members of their own, so visibility is
     * resolved through the owning project's roles — same as Version's
     * own relevantCustomFields().
     *
     * @return Collection<int, CustomField>
     */
    public function relevantCustomFields(): Collection
    {
        $fields = CustomField::query()
            ->where('customized_type', CustomizableType::Document)
            ->with('roles')
            ->orderBy('position')
            ->get();

        $user = auth()->user();

        if ($user?->is_admin) {
            return $fields->values();
        }

        $userRoles = $user ? app(AuthorizationService::class)->rolesFor($user, $this->project) : collect();

        return $fields->filter(fn (CustomField $field) => $field->visibleToRoles($userRoles))->values();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments');
    }

    /**
     * @return MediaCollection<int, Media>
     */
    public function attachments(): MediaCollection
    {
        return $this->getMedia('attachments');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
        ];
    }
}
