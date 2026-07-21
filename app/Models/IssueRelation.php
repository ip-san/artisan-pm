<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IssueRelationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['issue_from_id', 'issue_to_id', 'relation_type'])]
final class IssueRelation extends Model
{
    protected function casts(): array
    {
        return [
            'relation_type' => IssueRelationType::class,
        ];
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_from_id');
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_to_id');
    }
}
