<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WikiPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['project_id', 'parent_id', 'title', 'is_protected'])]
final class WikiPage extends Model
{
    /** @use HasFactory<WikiPageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_protected' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<WikiPage, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<WikiPage, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<WikiPageVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(WikiPageVersion::class)->orderByDesc('version');
    }

    /**
     * @return HasOne<WikiPageVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(WikiPageVersion::class)->latestOfMany('version');
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
}
