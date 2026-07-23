<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RepositoryCommitterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['repository_id', 'committer', 'user_id'])]
final class RepositoryCommitter extends Model
{
    /** @use HasFactory<RepositoryCommitterFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
