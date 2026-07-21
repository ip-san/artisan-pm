<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChangesetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['repository_id', 'revision', 'committer', 'committed_on', 'comments'])]
final class Changeset extends Model
{
    /** @use HasFactory<ChangesetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'committed_on' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * @return HasMany<ChangesetFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ChangesetFile::class);
    }

    /**
     * @return BelongsToMany<Issue, $this>
     */
    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class);
    }

    /**
     * Revision, shortened the way Git's own porcelain commands display it.
     */
    public function shortRevision(): string
    {
        return substr($this->revision, 0, 8);
    }
}
