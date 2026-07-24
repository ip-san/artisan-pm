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

    /**
     * Bounds the chain walk below, independently of
     * IssueService::MAX_RESCHEDULE_CHAIN_LENGTH (same value, kept as its
     * own constant since this is a structural safety net against
     * already-bad data, not tied to that class's reschedule cascade).
     */
    private const int MAX_CHAIN_LENGTH = 50;

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

    /**
     * Matches Redmine's Issue#would_reschedule?: would a new precedes/
     * follows relation between $predecessor and $successor be circular —
     * i.e. does $successor already (transitively) precede $predecessor?
     * Walks the precedes/follows chain forward from $successor, level by
     * level, same query shape as IssueService::rescheduleSuccessors().
     * Deliberately excludes Redmine's own parent/child leaves/ancestors
     * propagation — this app's reschedule feature already scopes itself
     * to the direct precedes/follows chain only (see that method's own
     * docblock), so this check stays consistent with what this app
     * actually treats as "the chain."
     */
    public static function wouldCreateCycle(Issue $predecessor, Issue $successor): bool
    {
        $visitedIds = [];
        $frontier = collect([$successor->id]);

        while ($frontier->isNotEmpty()) {
            if ($frontier->contains($predecessor->id)) {
                return true;
            }

            $visitedIds = [...$visitedIds, ...$frontier->all()];

            if (count($visitedIds) >= self::MAX_CHAIN_LENGTH) {
                return false;
            }

            $relations = self::query()
                ->where(fn ($query) => $query->whereIn('issue_from_id', $frontier)->where('relation_type', IssueRelationType::Precedes->value))
                ->orWhere(fn ($query) => $query->whereIn('issue_to_id', $frontier)->where('relation_type', IssueRelationType::Follows->value))
                ->get();

            $frontier = $relations
                ->map(fn (self $relation) => $relation->relation_type === IssueRelationType::Precedes ? $relation->issue_to_id : $relation->issue_from_id)
                ->reject(fn (int $id) => in_array($id, $visitedIds, true))
                ->unique()
                ->values();
        }

        return false;
    }
}
