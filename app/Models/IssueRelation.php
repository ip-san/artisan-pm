<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IssueRelationType;
use Database\Factories\IssueRelationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['issue_from_id', 'issue_to_id', 'relation_type', 'delay'])]
final class IssueRelation extends Model
{
    /** @use HasFactory<IssueRelationFactory> */
    use HasFactory;

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
