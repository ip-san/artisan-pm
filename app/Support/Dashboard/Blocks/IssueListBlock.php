<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\Issue;
use App\Models\User;
use App\Support\Dashboard\ConfigurableDashboardBlock;
use App\Support\Dashboard\DashboardBlockRow;
use App\Support\Dashboard\SavedIssueQueryBlock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The fixed issue blocks of My Page (assigned to me, reported, updated by
 * me, watched) with Redmine's per-block settings (my/blocks/_issues.erb):
 * the columns shown after the subject and the sort order, the same choices
 * as a saved query block. Without settings a block keeps its own order and
 * date.
 */
abstract class IssueListBlock implements ConfigurableDashboardBlock
{
    protected const int MAX_ROWS = 10;

    /**
     * The block's issues, visible to $user, without an order.
     *
     * @return Builder<Issue>
     */
    abstract protected function issues(User $user): Builder;

    /**
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    abstract protected function defaultOrder(Builder $query): Builder;

    abstract protected function defaultMeta(Issue $issue, User $user): ?string;

    public function settingFields(): array
    {
        return [
            'columns' => ['label' => __('表示する項目'), 'type' => 'columns', 'options' => SavedIssueQueryBlock::columnLabels()],
            'sort' => ['label' => __('並び順(空欄は既定の並び順)'), 'type' => 'select', 'options' => SavedIssueQueryBlock::sortOptions()],
        ];
    }

    public function normalizeSettings(array $input): array
    {
        return SavedIssueQueryBlock::normalizeSettings($input);
    }

    public function rows(User $user): Collection
    {
        return $this->rowsWithSettings($user, []);
    }

    public function rowsWithSettings(User $user, array $settings): Collection
    {
        $settings = $this->normalizeSettings($settings);
        $query = $this->issues($user)->with(['project', 'tracker', 'status', 'priority', 'assignedTo', 'assignedToGroup', 'author']);

        if (isset($settings['sort'])) {
            [$column, $direction] = explode(':', $settings['sort']);
            $query->orderBy("issues.{$column}", $direction)->orderBy('issues.id', $direction);
        } else {
            $query = $this->defaultOrder($query);
        }

        return $query
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Issue $issue) => new DashboardBlockRow(
                title: "{$issue->tracker->name} #{$issue->id}: {$issue->subject}",
                url: route('issues.show', [$issue->project, $issue]),
                meta: isset($settings['columns']) ? SavedIssueQueryBlock::metaFor($issue, $settings['columns']) : $this->defaultMeta($issue, $user),
            ));
    }
}
