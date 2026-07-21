<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['issue_id', 'user_id', 'notes', 'private_notes'])]
final class Journal extends Model
{
    protected function casts(): array
    {
        return [
            'private_notes' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<JournalDetail, $this>
     */
    public function details(): HasMany
    {
        return $this->hasMany(JournalDetail::class);
    }

    public function isEmpty(): bool
    {
        return blank($this->notes) && $this->details->isEmpty();
    }
}
