<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NewsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['project_id', 'author_id', 'title', 'summary', 'description'])]
final class News extends Model implements HasMedia
{
    /** @use HasFactory<NewsFactory> */
    use HasFactory, InteractsWithMedia, Searchable;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<NewsComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(NewsComment::class)->orderBy('created_at');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments');
    }

    /**
     * @return MorphMany<Watcher, $this>
     */
    public function watchers(): MorphMany
    {
        return $this->morphMany(Watcher::class, 'watchable');
    }

    public function isWatchedBy(User $user): bool
    {
        return $this->watchers->contains('user_id', $user->id);
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
            'summary' => $this->summary,
            'description' => $this->description,
        ];
    }
}
