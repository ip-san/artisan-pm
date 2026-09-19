<?php

declare(strict_types=1);

namespace App\Support\Pagination;

use App\Models\Setting;

/**
 * Redmine's per_page_options: the page sizes a list offers in its
 * "表示件数" selector. A requested size counts only when it is one of the
 * configured options (Redmine's per_page_option), so a hand-edited URL
 * cannot ask for an arbitrarily large page.
 */
final class PageSize
{
    public const string DEFAULT_OPTIONS = '25,50,100';

    public const int DEFAULT_SEARCH_RESULTS = 10;

    /**
     * Space- or comma-separated positive integers, ascending — the parsing
     * of Setting.per_page_options_array. Unparseable input yields no options
     * and therefore no selector.
     *
     * @return array<int, int>
     */
    public static function options(): array
    {
        $raw = Setting::get('per_page_options', self::DEFAULT_OPTIONS);

        return self::parse(is_string($raw) ? $raw : self::DEFAULT_OPTIONS);
    }

    /**
     * @return array<int, int>
     */
    public static function parse(string $raw): array
    {
        $values = array_map('intval', preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $values = array_values(array_unique(array_filter($values, fn (int $value) => $value > 0)));
        sort($values);

        return $values;
    }

    public static function resolve(?int $requested, int $default): int
    {
        return $requested !== null && in_array($requested, self::options(), true) ? $requested : $default;
    }

    /**
     * The sizes worth offering for a list of $itemCount rows: nothing above
     * the smallest option that already shows everything, plus the size in
     * use. Empty when there is nothing to choose between.
     *
     * @return array<int, int>
     */
    public static function selectableFor(int $selected, int $itemCount): array
    {
        $options = self::options();

        if ($options !== []) {
            if ($itemCount > $options[0]) {
                $max = collect($options)->first(fn (int $value) => $value >= $itemCount) ?? $itemCount;
            } else {
                $max = $itemCount;
            }

            $options = array_values(array_filter($options, fn (int $value) => $value <= $max || $value === $selected));
        }

        if ($options === [] || (count($options) === 1 && $options[0] === $selected)) {
            return [];
        }

        return $options;
    }

    public static function searchResults(): int
    {
        $value = (int) Setting::get('search_results_per_page', self::DEFAULT_SEARCH_RESULTS);

        return $value > 0 ? $value : self::DEFAULT_SEARCH_RESULTS;
    }
}
