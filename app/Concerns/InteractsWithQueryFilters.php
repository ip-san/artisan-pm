<?php

declare(strict_types=1);

namespace App\Concerns;

use Livewire\Attributes\Url;

/**
 * Shared filter-builder state and actions for every Volt list backed by
 * QueryFilterEngine (issues, time entries, gantt) — the Blade half
 * already lives in <x-query-filter-builder>; this is the PHP half all
 * three previously duplicated byte-for-byte. The consuming component
 * supplies its own #[Computed] engine(): QueryFilterEngine and calls
 * builtFilters() from its own query-building method; applyFilters()
 * stays per-component since each page unsets a different set of
 * #[Computed] properties.
 */
trait InteractsWithQueryFilters
{
    /** @var array<int, string> */
    #[Url]
    public array $activeFilterKeys = [];

    /**
     * Also URL-backed, alongside activeFilterKeys — together they let a
     * link from outside this list (e.g. the roadmap's per-version issue
     * counts) deep-link straight into a pre-filtered list, matching
     * Redmine's own f[]/op[]/v[][] query-string filter scheme. A key
     * present in activeFilterKeys but missing an operator here still
     * degrades gracefully: builtFilters() below just skips it.
     *
     * @var array<string, string>
     */
    #[Url]
    public array $filterOperators = [];

    /** @var array<string, array<int, mixed>> */
    #[Url]
    public array $filterValues = [];

    /**
     * @return array<string, array{operator: string, values: array<int, mixed>}>
     */
    private function builtFilters(): array
    {
        $filters = [];

        foreach ($this->activeFilterKeys as $key) {
            $operator = $this->filterOperators[$key] ?? null;

            if ($operator === null) {
                continue;
            }

            $filters[$key] = [
                'operator' => $operator,
                'values' => array_values(array_filter($this->filterValues[$key] ?? [], fn ($v) => $v !== null && $v !== '')),
            ];
        }

        return $filters;
    }

    public function addFilter(string $key): void
    {
        if (! in_array($key, $this->activeFilterKeys, true) && $this->engine->field($key) !== null) {
            $this->activeFilterKeys[] = $key;
            $this->filterOperators[$key] = $this->engine->field($key)->operators()[0]->value;
        }
    }

    public function removeFilter(string $key): void
    {
        $this->activeFilterKeys = array_values(array_diff($this->activeFilterKeys, [$key]));
        unset($this->filterOperators[$key], $this->filterValues[$key]);
    }
}
