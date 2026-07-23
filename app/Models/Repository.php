<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RepositoryType;
use App\Support\Scm\GitAdapter;
use App\Support\Scm\ScmAdapter;
use App\Support\Scm\SvnAdapter;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'type', 'path', 'last_synced_revision'])]
final class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => RepositoryType::class,
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
}
