<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tracker_id', 'role_id', 'old_status_id', 'new_status_id', 'author', 'assignee'])]
class WorkflowTransition extends Model
{
    protected function casts(): array
    {
        return [
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
    public function oldStatus(): BelongsTo
    {
        return $this->belongsTo(IssueStatus::class, 'old_status_id');
    }

    /**
     * @return BelongsTo<IssueStatus, $this>
     */
    public function newStatus(): BelongsTo
    {
        return $this->belongsTo(IssueStatus::class, 'new_status_id');
    }
}
