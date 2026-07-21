<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkflowFieldRuleType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tracker_id', 'role_id', 'status_id', 'field_name', 'rule', 'author', 'assignee'])]
final class WorkflowFieldRule extends Model
{
    protected function casts(): array
    {
        return [
            'rule' => WorkflowFieldRuleType::class,
            'author' => 'boolean',
            'assignee' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tracker, $this>
     */
    public function tracker(): BelongsTo
    {
        return $this->belongsTo(Tracker::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<IssueStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(IssueStatus::class);
    }
}
