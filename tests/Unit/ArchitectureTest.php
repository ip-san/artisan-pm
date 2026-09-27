<?php

use Illuminate\Support\Facades\File;

arch('debugging helpers are not left in the code')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('env() is read only in config files')
    ->expect('env')
    ->not->toBeUsed();

/**
 * Raw SQL call sites that interpolate a variable. Each one was reviewed on 2026-09-27: the
 * interpolated part is an identifier or expression built from constants/whitelists, and user
 * values go through ? bindings. A new call site fails until it is reviewed and added here.
 */
const REVIEWED_RAW_SQL = [
    'app/Models/User.php: $query->orderByRaw("LOWER({$expression})");',
    'app/Rules/UniqueUserValueIgnoringCase.php: ->whereRaw(\'lower(\'.$this->column.\') = ?\', [mb_strtolower((string) $value)])',
    'app/Support/Activity/ProjectLastActivity.php: ->selectRaw("MAX({$timeColumn}) as last_activity_at")',
    'app/Support/Query/IssueExtraFilterFields.php: FilterOperator::IsEmpty => $query->whereRaw("{$hours} = 0"),',
    'app/Support/Query/IssueExtraFilterFields.php: FilterOperator::IsNotEmpty => $query->whereRaw("{$hours} > 0"),',
    'app/Support/Query/IssueExtraFilterFields.php: FilterOperator::GreaterOrEqual => $query->whereRaw("{$hours} >= ?", [$first]),',
    'app/Support/Query/IssueExtraFilterFields.php: FilterOperator::LessOrEqual => $query->whereRaw("{$hours} <= ?", [$first]),',
    'app/Support/Query/IssueExtraFilterFields.php: FilterOperator::Between => $query->whereRaw("{$hours} BETWEEN ? AND ?", [$first, round((float) $values[1], 2)]),',
    'app/Support/Query/IssueExtraFilterFields.php: default => $query->whereRaw("{$hours} = ?", [$first]),',
    'app/Support/Query/TimeEntryColumns.php: $query->orderByRaw("{$sql} IS NOT NULL {$order}, {$sql} {$order}", [...$value->getBindings(), ...$value->getBindings()]);',
    'app/Support/Reports/IssueReport.php: ->selectRaw("{$column} as dimension_value, status_id, COUNT(*) as total")',
    'app/Support/Reports/IssueReport.php: ->groupByRaw("{$column}, status_id")',
    'resources/views/livewire/issues/index.blade.php: ->selectRaw("custom_field_values.{$storageColumn} as group_key, SUM(time_entries.hours) as spent")',
    'resources/views/livewire/issues/index.blade.php: ->selectRaw("custom_field_values.{$storageColumn} as group_key, COUNT(*) as total, COALESCE(SUM(issues.estimated_hours), 0) as estimated, ".self::REMAINING_SUM.\' as remaining\')',
    'resources/views/livewire/issues/index.blade.php: ->selectRaw("{$groupKey} as group_key, SUM(total_values.{$field->format()->storageColumn()}) as total")',
];

test('raw SQL interpolates only reviewed identifiers, never values', function () {
    $pattern = '/(Raw|DB::(raw|statement|select|unprepared))\(\s*("[^"]*\$|[^,)]*\.\s*\$)/';
    $found = [];

    foreach ([...File::allFiles(base_path('app')), ...File::allFiles(resource_path('views'))] as $file) {
        foreach (file($file->getPathname()) as $line) {
            if (preg_match($pattern, $line)) {
                $found[] = str_replace(base_path().'/', '', $file->getPathname()).': '.trim($line);
            }
        }
    }

    $unreviewed = array_diff($found, REVIEWED_RAW_SQL);

    expect($unreviewed)->toBeEmpty("Unreviewed raw SQL interpolation (bind values with ?, or review and add to REVIEWED_RAW_SQL):\n".implode("\n", $unreviewed));
});
