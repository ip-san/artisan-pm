<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A My Page block with settings of its own (Redmine's my_page_settings).
 * `rows()` reads them through `rowsWithSettings()`; whatever the user saved
 * is passed through `normalizeSettings()` first, so a stale or hand-edited
 * value never reaches the query.
 */
interface ConfigurableDashboardBlock extends DashboardBlock
{
    /**
     * The form fields, keyed by setting name.
     *
     * @return array<string, array{label: string, type: 'number'|'select'|'columns', options?: array<string, string>, placeholder?: string}>
     */
    public function settingFields(): array;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeSettings(array $input): array;

    /**
     * @param  array<string, mixed>  $settings
     * @return Collection<int, DashboardBlockRow>
     */
    public function rowsWithSettings(User $user, array $settings): Collection;
}
