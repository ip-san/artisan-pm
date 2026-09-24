<?php

declare(strict_types=1);

namespace App\Support\Dashboard\Blocks;

use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Dashboard\ConfigurableDashboardBlock;
use App\Support\Dashboard\DashboardBlockRow;
use App\Support\Format\DateTimes;
use Illuminate\Support\Collection;

final class TimeEntriesBlock implements ConfigurableDashboardBlock
{
    private const int MAX_ROWS = 10;

    public function key(): string
    {
        return 'time_entries';
    }

    public function label(): string
    {
        return __('最近の工数');
    }

    public function settingFields(): array
    {
        return ['days' => ['label' => __('表示する日数(空欄は期間を限らない)'), 'type' => 'number', 'placeholder' => '7']];
    }

    public function normalizeSettings(array $input): array
    {
        $days = (int) ($input['days'] ?? 0);

        return $days >= 1 ? ['days' => min($days, 365)] : [];
    }

    public function rows(User $user): Collection
    {
        return $this->rowsWithSettings($user, []);
    }

    public function rowsWithSettings(User $user, array $settings): Collection
    {
        $days = (int) ($this->normalizeSettings($settings)['days'] ?? 0);

        return TimeEntry::query()
            ->where('user_id', $user->id)
            ->when($days > 0, fn ($query) => $query->whereDate('spent_on', '>=', DateTimes::today($user)->subDays($days - 1)->toDateString()))
            ->with(['project', 'activity'])
            ->latest('spent_on')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (TimeEntry $entry) => new DashboardBlockRow(
                title: "{$entry->project->name} — {$entry->activity->name} ({$entry->hours}h)",
                url: route('time-entries.index', $entry->project),
                meta: DateTimes::date($entry->spent_on),
            ));
    }
}
