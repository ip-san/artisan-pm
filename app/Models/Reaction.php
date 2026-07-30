<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single thumbs-up "like" on a reactable object — matches Redmine's
 * Reaction model. There is no `type` column: Redmine's reaction feature is
 * one binary reaction per user per object, not a multi-emoji picker.
 */
#[Fillable(['user_id'])]
final class Reaction extends Model
{
    /** @use HasFactory<ReactionFactory> */
    use HasFactory;

    /**
     * @return MorphTo<Model, $this>
     */
    public function reactable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
