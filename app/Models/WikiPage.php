<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasThumbnails;
use Database\Factories\WikiPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['project_id', 'parent_id', 'title', 'is_protected'])]
final class WikiPage extends Model implements HasMedia
{
    /** @use HasFactory<WikiPageFactory> */
    use HasFactory, HasThumbnails, InteractsWithMedia {
        HasThumbnails::registerMediaConversions insteadof InteractsWithMedia;
    }

    /**
     * Wiki page titles (case-insensitive) that are always protected on
     * creation — matches Redmine's WikiPage::DEFAULT_PROTECTED_PAGES.
     * "Sidebar" is the one Redmine core ships with: its content renders
     * into the sidebar of every page on the wiki, so accidental edits
     * would affect the whole project's wiki chrome.
     *
     * @var array<int, string>
     */
    public const array DEFAULT_PROTECTED_PAGES = ['sidebar'];

    protected function casts(): array
    {
        return [
            'is_protected' => 'boolean',
        ];
    }

    /**
     * Matches Redmine's WikiPage#delete_redirects (a before_destroy
     * callback there) — a redirect that now points at a deleted page
     * would only ever resolve to a 404, so it's cleaned up too.
     */
    protected static function booted(): void
    {
        self::creating(function (WikiPage $page) {
            if (in_array(mb_strtolower($page->title), self::DEFAULT_PROTECTED_PAGES, true)) {
                $page->is_protected = true;
            }
        });

        self::deleting(function (WikiPage $page) {
            // Matches same-project redirects (redirects_to_project_id
            // null, implicitly this page's own project) as well as
            // cross-project redirects left behind by a move — both would
            // otherwise resolve to a 404 now that the target is gone.
            WikiRedirect::query()
                ->where('redirects_to', $page->title)
                ->where(function ($query) use ($page) {
                    $query->where('redirects_to_project_id', $page->project_id)
                        ->orWhere(function ($query) use ($page) {
                            $query->whereNull('redirects_to_project_id')->where('project_id', $page->project_id);
                        });
                })
                ->delete();
        });
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
}
