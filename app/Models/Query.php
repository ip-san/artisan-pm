<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QueryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'type', 'user_id', 'project_id', 'is_public',
    'filters', 'column_names', 'sort_criteria', 'group_by',
])]
final class Query extends Model
{
    protected function casts(): array
    {
        return [
            'type' => QueryType::class,
            'is_public' => 'boolean',
            'filters' => 'array',
            'column_names' => 'array',
            'sort_criteria' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function visibleTo(User $user): bool
    {
        return $this->is_public || $this->user_id === $user->id;
    }
}
