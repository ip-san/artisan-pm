<?php

declare(strict_types=1);

namespace App\Support\Query;

/**
 * Turns the filter/sort state a list keeps in its URL (Redmine's
 * f[]/op[]/v[] scheme, spelled activeFilterKeys / filterOperators /
 * filterValues here, plus up to three sort levels) into what
 * QueryFilterEngine consumes — and back into query-string parameters.
 * Shared by the Livewire lists and by endpoints that must honour the same
 * state without a component, such as the issue Atom feed, so both read the
 * URL identically. Everything is sanitised: a request can put anything in
 * a query string, and the engine only ever sees plain scalar values.
 */
final class ListQueryString
{
    /**
     * @param  array<int, mixed>  $keys
     * @param  array<string, mixed>  $operators
     * @param  array<string, mixed>  $values
     * @return array<string, array{operator: string, values: array<int, mixed>}>
     */
    public static function filters(array $keys, array $operators, array $values): array
    {
        $filters = [];

        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }

            $operator = $operators[$key] ?? null;

            if (! is_string($operator)) {
                continue;
            }

            $rawValues = $values[$key] ?? [];

            $filters[$key] = [
                'operator' => $operator,
                'values' => array_values(array_filter(
                    is_array($rawValues) ? $rawValues : [$rawValues],
                    fn ($value) => (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) && $value !== '',
                )),
            ];
        }

        return $filters;
    }

    /**
     * Up to 3 [key, direction] pairs — Redmine's own cap on how many
     * columns a list can be sorted by. The 2nd/3rd levels only count once
     * a primary key is set.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function sortCriteria(
        ?string $key1,
        string $direction1 = 'asc',
        ?string $key2 = null,
        string $direction2 = 'asc',
        ?string $key3 = null,
        string $direction3 = 'asc',
    ): array {
        if ($key1 === null || $key1 === '') {
            return [];
        }

        $criteria = [[$key1, self::direction($direction1)]];

        if ($key2 !== null && $key2 !== '') {
            $criteria[] = [$key2, self::direction($direction2)];
        }

        if ($key3 !== null && $key3 !== '') {
            $criteria[] = [$key3, self::direction($direction3)];
        }

        return $criteria;
    }

    /**
     * Reads the URL-shaped state out of raw request query parameters,
     * tolerating wrong types (a bare string where an array belongs, and so
     * on) instead of erroring.
     *
     * @param  array<string, mixed>  $input
     * @return array{filters: array<string, array{operator: string, values: array<int, mixed>}>, sort: array<int, array{0: string, 1: string}>}
     */
    public static function fromRequestInput(array $input): array
    {
        $asArray = fn (mixed $value): array => is_array($value) ? $value : [];
        $asKey = fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $asDirection = fn (mixed $value): string => is_string($value) ? $value : 'asc';

        return [
            'filters' => self::filters($asArray($input['activeFilterKeys'] ?? []), $asArray($input['filterOperators'] ?? []), $asArray($input['filterValues'] ?? [])),
            'sort' => self::sortCriteria(
                $asKey($input['sortKey'] ?? null),
                $asDirection($input['sortDirection'] ?? 'asc'),
                $asKey($input['sortKey2'] ?? null),
                $asDirection($input['sortDirection2'] ?? 'asc'),
                $asKey($input['sortKey3'] ?? null),
                $asDirection($input['sortDirection3'] ?? 'asc'),
            ),
        ];
    }

    /**
     * The query-string parameters that reproduce a list's current state,
     * for links to endpoints that read them through fromRequestInput().
     *
     * @param  array<int, string>  $keys
     * @param  array<string, string>  $operators
     * @param  array<string, array<int, mixed>>  $values
     * @return array<string, mixed>
     */
    public static function toQueryParameters(
        array $keys,
        array $operators,
        array $values,
        ?string $sortKey = null,
        string $sortDirection = 'asc',
        ?string $sortKey2 = null,
        string $sortDirection2 = 'asc',
        ?string $sortKey3 = null,
        string $sortDirection3 = 'asc',
    ): array {
        $parameters = [];

        if ($keys !== []) {
            $parameters['activeFilterKeys'] = array_values($keys);
            $parameters['filterOperators'] = array_intersect_key($operators, array_flip($keys));
            $parameters['filterValues'] = array_intersect_key($values, array_flip($keys));
        }

        foreach ([[$sortKey, $sortDirection, ''], [$sortKey2, $sortDirection2, '2'], [$sortKey3, $sortDirection3, '3']] as [$key, $direction, $suffix]) {
            if ($key !== null && $key !== '') {
                $parameters['sortKey'.$suffix] = $key;
                $parameters['sortDirection'.$suffix] = self::direction($direction);
            }
        }

        return $parameters;
    }

    private static function direction(string $direction): string
    {
        return strtolower($direction) === 'desc' ? 'desc' : 'asc';
    }
}
