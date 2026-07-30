<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RepositoryType;
use App\Support\Scm\GitAdapter;
use App\Support\Scm\ScmAdapter;
use App\Support\Scm\SvnAdapter;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'type', 'path', 'last_synced_revision', 'is_default', 'identifier'])]
final class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => RepositoryType::class,
            'is_default' => 'boolean',
        ];
    }

    /**
     * Mirrors Redmine's Repository#check_default (repository.rb): a
     * project's first repository is always its default, and marking any
     * repository as default unsets the previous one — a single point of
     * truth so every write path (factory, admin form, future multi-repo
     * UI) gets this invariant for free rather than each caller managing it.
     *
     * The guards below matter beyond tidiness: RepositorySyncService::sync()
     * calls $repository->update() once per synced changeset to advance
     * last_synced_revision. Without them, every one of those saves would
     * re-run the "unset every other project repository" sweep even though
     * is_default never changed — an extra UPDATE per changeset on a save
     * path that already runs in a tight loop. Redmine guards the
     * equivalent sweep the same way (`is_default_changed?`,
     * repository.rb:517). `wasChanged()` isn't usable for this at insert
     * time — Eloquent syncs the model's original state before the
     * `created`/`saved` events fire, so `wasChanged('is_default')` reads
     * false on a freshly-inserted row no matter what was passed in,
     * silently skipping the sweep a brand-new non-default-project's
     * repository needs. `updated` doesn't have this problem (the
     * pre-update value is genuinely still tracked as "original" there),
     * so insert and update are handled by separate listeners: `created`
     * sweeps unconditionally whenever is_default is true, `updated` sweeps
     * only when `wasChanged('is_default')`.
     */
    protected static function booted(): void
    {
        self::saving(function (self $repository) {
            if (! $repository->is_default
                && ! $repository->exists
                && ! static::query()->where('project_id', $repository->project_id)->exists()) {
                $repository->is_default = true;
            }

            // Matches Redmine's Repository#identifier= override
            // (repository.rb:126-128), which silently ignores any write
            // once identifier_frozen? is true rather than raising a
            // validation error — an already-set identifier is baked into
            // that repository's URLs (once slice 2's routing exists), so
            // silently changing it out from under a bookmarked/linked URL
            // would be worse than refusing the edit outright. Blank
            // (null or '') counts as "not set" here — same as
            // identifierParam() below and Redmine's own identifier.blank?
            // — so a repository saved once with an untouched, blank
            // identifier field (what Livewire submits for an empty text
            // input, not null) can still receive its first real identifier
            // later rather than being frozen on a value nobody ever chose.
            if ($repository->exists
                && ($repository->getOriginal('identifier') ?? '') !== ''
                && $repository->isDirty('identifier')) {
                $repository->identifier = $repository->getOriginal('identifier');
            }
        });

        self::created(function (self $repository) {
            if ($repository->is_default) {
                $repository->unsetOtherDefaultsForProject();
            }
        });

        self::updated(function (self $repository) {
            if ($repository->is_default && $repository->wasChanged('is_default')) {
                $repository->unsetOtherDefaultsForProject();
            }
        });
    }

    private function unsetOtherDefaultsForProject(): void
    {
        self::query()
            ->where('project_id', $this->project_id)
            ->whereKeyNot($this->getKey())
            ->update(['is_default' => false]);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Changeset, $this>
     */
    public function changesets(): HasMany
    {
        return $this->hasMany(Changeset::class)->orderByDesc('committed_on');
    }

    /**
     * @return HasMany<RepositoryCommitter, $this>
     */
    public function committers(): HasMany
    {
        return $this->hasMany(RepositoryCommitter::class);
    }

    public function adapter(): ScmAdapter
    {
        return match ($this->type) {
            RepositoryType::Git => new GitAdapter($this->path),
            RepositoryType::Svn => new SvnAdapter($this->path),
        };
    }

    /**
     * Matches Redmine's Repository#identifier_param (repository.rb:135):
     * the identifier when set, otherwise the numeric id — what a URL
     * should embed to reference this repository. Not yet consumed by any
     * route (that's slice 2b); this and whereIdentifierParam() exist now
     * so the resolution logic has test coverage independent of routing.
     */
    public function identifierParam(): string
    {
        return $this->identifier !== null && $this->identifier !== '' ? $this->identifier : (string) $this->id;
    }

    /**
     * Matches Redmine's Repository.find_by_identifier_param
     * (repository.rb:151-157): a purely-numeric param is treated as an id
     * lookup, anything else as an identifier lookup — never both, so an
     * identifier that happens to look like "123" can only ever be reached
     * by the id it collides with (Redmine's own format validation
     * excludes purely-numeric identifiers for exactly this reason, though
     * that validation itself belongs to slice 2b's form, not here).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereIdentifierParam(Builder $query, string $param): Builder
    {
        return preg_match('/^\d+$/', $param) === 1
            ? $query->whereKey($param)
            : $query->where('identifier', $param);
    }

    /**
     * Slice 2b: every repository.* route now has an identifier-bearing
     * sibling registered under the same base name with a ".repo" suffix
     * (see routes/web.php) — this picks the right one so links generated
     * against a non-default repository stay on that repository instead of
     * silently falling back to the project's default one.
     */
    public function routeName(string $baseName): string
    {
        return $this->is_default ? $baseName : $baseName.'.repo';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function routeParameters(array $extra = []): array
    {
        $params = ['project' => $this->project, ...$extra];

        if (! $this->is_default) {
            $params['repositoryParam'] = $this->identifierParam();
        }

        return $params;
    }
}
