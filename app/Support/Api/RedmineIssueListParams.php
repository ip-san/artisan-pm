<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Models\IssueStatus;
use App\Models\User;
use App\Support\Query\FilterableField;
use App\Support\Query\FilterSelectOptions;
use App\Support\Query\QueryFilterEngine;
use Carbon\CarbonImmutable;

/**
 * Reads the REST issue list's Redmine-style query parameters into what
 * QueryFilterEngine and the paginator consume, so GET /issues.json takes
 * the same filters as the web list (Redmine's Query#build_from_params and
 * ApplicationController#api_offset_and_limit):
 *
 * - `f[]=field&op[field]=operator&v[field][]=value`, or, without f[], the
 *   short form `field=[operator]value[|value]` for any filter;
 * - `limit` (1–100, 25 when missing or invalid) and `offset`, or `page`
 *   counted in `limit`-sized pages.
 *
 * Field names are Redmine's: created_on/updated_on/closed_on, and dotted
 * names such as fixed_version.due_date, which are keyed with an
 * underscore here. Anything the engine does not know — a field, an
 * operator, a malformed value — is skipped rather than rejected; the
 * filters only ever narrow a query the caller has already limited to the
 * issues the user may see.
 */
final class RedmineIssueListParams
{
    public const int DEFAULT_LIMIT = 25;

    public const int MAX_LIMIT = 100;

    /**
     * Redmine's operators that this app spells differently or does not
     * have; the others (=, !, ~, !~, >=, <=, ><, *o, !o, =p, =!p, !p) are
     * FilterOperator values as they are.
     *
     * @var array<int, string>
     */
    private const array SHORT_FILTER_OPERATORS = [
        '=p', '=!p', '!p', '*o', '!o', '!*', '!~', '>=', '<=', '><', '>t-', '<t-', 't-', 'l2w', 'ld', 'lw', 'lm', '!', '*', '~', 'o', 'c', 't', 'w', 'm', 'y',
    ];

    /**
     * Redmine's relative date operators, which only a date filter takes.
     *
     * @var array<int, string>
     */
    private const array DATE_OPERATORS = ['>t-', '<t-', 't-', 't', 'ld', 'w', 'lw', 'l2w', 'm', 'lm', 'y'];

    /**
     * @param  array<string, mixed>  $input  the request's query parameters
     * @param  array<int, string>  $ignoredShortKeys  parameters the caller already handles itself
     * @return array<string, array{operator: string, values: array<int, mixed>}>
     */
    public static function filters(array $input, QueryFilterEngine $engine, User $user, array $ignoredShortKeys = []): array
    {
        $fields = $input['f'] ?? $input['fields'] ?? null;

        if (is_array($fields)) {
            $operators = is_array($input['op'] ?? null) ? $input['op'] : [];
            $values = is_array($input['v'] ?? null) ? $input['v'] : [];
            $filters = [];

            foreach ($fields as $name) {
                if (! is_string($name) || ! is_string($operators[$name] ?? null)) {
                    continue;
                }

                $raw = $values[$name] ?? [];
                $filter = self::translate($name, $operators[$name], is_array($raw) ? array_values($raw) : [$raw], $engine, $user);

                if ($filter !== null) {
                    $filters[self::fieldKey($name)] = $filter;
                }
            }

            return $filters;
        }

        $filters = [];

        foreach ($input as $name => $expression) {
            if (! is_string($name) || ! is_string($expression) || in_array($name, $ignoredShortKeys, true) || $engine->field(self::fieldKey($name)) === null) {
                continue;
            }

            $filter = self::translateShortExpression($name, $expression, $engine, $user);

            if ($filter !== null) {
                $filters[self::fieldKey($name)] = $filter;
            }
        }

        return $filters;
    }

    /**
     * Redmine's api_offset_and_limit.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: int, 1: int} [offset, limit]
     */
    public static function offsetAndLimit(array $input): array
    {
        $limit = is_numeric($input['limit'] ?? null) ? (int) $input['limit'] : 0;
        $limit = $limit < 1 ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);

        if (is_numeric($input['offset'] ?? null)) {
            return [max(0, (int) $input['offset']), $limit];
        }

        if (is_numeric($input['page'] ?? null)) {
            return [max(0, ((int) $input['page'] - 1) * $limit), $limit];
        }

        return [0, $limit];
    }

    /**
     * The engine key for a Redmine filter name.
     */
    public static function fieldKey(string $name): string
    {
        return match ($name) {
            'created_on' => 'created_at',
            'updated_on' => 'updated_at',
            default => array_flip(FilterSelectOptions::DOTTED_KEYS)[$name] ?? $name,
        };
    }

    /**
     * Redmine's add_short_filter: the longest operator the expression
     * starts with that the field accepts, else "is" with the whole
     * expression ("subject=open" is a subject, not the "open" operator).
     *
     * @return ?array{operator: string, values: array<int, mixed>}
     */
    private static function translateShortExpression(string $name, string $expression, QueryFilterEngine $engine, User $user): ?array
    {
        foreach (self::SHORT_FILTER_OPERATORS as $operator) {
            if (! str_starts_with($expression, $operator)) {
                continue;
            }

            $rest = substr($expression, strlen($operator));
            $filter = self::translate($name, $operator, $rest === '' ? [] : explode('|', $rest), $engine, $user);

            if ($filter !== null) {
                return $filter;
            }
        }

        return self::translate($name, '=', explode('|', $expression), $engine, $user);
    }

    /**
     * One Redmine filter as an engine filter, or null when the field or
     * the operator is not one the engine offers.
     *
     * @param  array<int, mixed>  $values
     * @return ?array{operator: string, values: array<int, mixed>}
     */
    private static function translate(string $name, string $redmineOperator, array $values, QueryFilterEngine $engine, User $user): ?array
    {
        $field = $engine->field(self::fieldKey($name));

        if ($field === null
            || (in_array($redmineOperator, ['o', 'c'], true) && $name !== 'status_id')
            || (in_array($redmineOperator, self::DATE_OPERATORS, true) && $field->type() !== FilterFieldType::Date)) {
            return null;
        }

        $values = array_values(array_filter($values, fn ($value) => is_scalar($value) && (string) $value !== ''));

        // `me` for the assignee is left to AssigneeFilter, which adds the
        // caller's groups as Redmine does.
        if (self::fieldKey($name) === 'author_id') {
            $values = array_map(fn ($value) => $value === 'me' ? (string) $user->id : $value, $values);
        }

        $today = CarbonImmutable::today();
        $days = (int) ($values[0] ?? 0);

        [$operator, $values] = match ($redmineOperator) {
            '*' => [FilterOperator::IsNotEmpty, []],
            '!*' => [FilterOperator::IsEmpty, []],
            // No status of the kind at all: an id no status has, so the
            // filter matches nothing instead of being skipped as blank.
            'o', 'c' => [FilterOperator::In, IssueStatus::query()->where('is_closed', $redmineOperator === 'c')->pluck('id')->map(fn (int $id) => (string) $id)->whenEmpty(fn ($none) => $none->push('0'))->all()],
            '=' => [count($values) > 1 && in_array(FilterOperator::In, $field->operators(), true) ? FilterOperator::In : FilterOperator::Equals, $values],
            '!' => [count($values) > 1 && in_array(FilterOperator::NotIn, $field->operators(), true) ? FilterOperator::NotIn : FilterOperator::NotEquals, $values],
            '>t-' => [FilterOperator::InTheLastDays, [$days]],
            '<t-' => [FilterOperator::LessOrEqual, [$today->subDays($days)->toDateString()]],
            't-' => [FilterOperator::Between, [$today->subDays($days)->toDateString(), $today->subDays($days)->toDateString()]],
            't' => [FilterOperator::Between, [$today->toDateString(), $today->toDateString()]],
            'ld' => [FilterOperator::Between, [$today->subDay()->toDateString(), $today->subDay()->toDateString()]],
            'w' => [FilterOperator::Between, [$today->startOfWeek()->toDateString(), $today->endOfWeek()->toDateString()]],
            'lw' => [FilterOperator::Between, [$today->subWeek()->startOfWeek()->toDateString(), $today->subWeek()->endOfWeek()->toDateString()]],
            'l2w' => [FilterOperator::Between, [$today->subWeek()->startOfWeek()->toDateString(), $today->endOfWeek()->toDateString()]],
            'm' => [FilterOperator::Between, [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()]],
            'lm' => [FilterOperator::Between, [$today->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->subMonthNoOverflow()->endOfMonth()->toDateString()]],
            'y' => [FilterOperator::Between, [$today->startOfYear()->toDateString(), $today->endOfYear()->toDateString()]],
            default => [FilterOperator::tryFrom($redmineOperator), $values],
        };

        if ($operator === null || ! in_array($operator, $field->operators(), true)) {
            return null;
        }

        $values = self::wellFormedValues($field, $operator, $values);

        if ($operator->requiresValue() && ($values === [] || ($operator === FilterOperator::Between && count($values) < 2))) {
            return null;
        }

        // Redmine's date filters compare whole days: on a timestamp column
        // "is" a day means that whole day, and "until" a day includes it.
        $isDay = fn (mixed $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) === 1;

        if ($field->type() === FilterFieldType::Date) {
            if ($operator === FilterOperator::Equals && $isDay($values[0] ?? null) && in_array(FilterOperator::Between, $field->operators(), true)) {
                [$operator, $values] = [FilterOperator::Between, [$values[0], $values[0]]];
            }

            if ($operator === FilterOperator::Between && count($values) === 2 && $isDay($values[1])) {
                $values[1] .= ' 23:59:59';
            }

            if ($operator === FilterOperator::LessOrEqual && $isDay($values[0] ?? null)) {
                $values[0] .= ' 23:59:59';
            }
        }

        return ['operator' => $operator->value, 'values' => $values];
    }

    /**
     * The values the field can take: a malformed one ("abc" for a tracker,
     * a word for a date) is dropped so it never reaches the SQL, and a
     * choice must be one the web list would offer the user.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, mixed>
     */
    private static function wellFormedValues(FilterableField $field, FilterOperator $operator, array $values): array
    {
        if (! $operator->requiresValue()) {
            return [];
        }

        $isNumber = fn (mixed $value): bool => is_numeric($value);

        $isWellFormed = match (true) {
            $operator->takesProject(), $field->type() === FilterFieldType::Select && $field->options() !== [] => fn (mixed $value): bool => array_key_exists((string) $value, $field->options()),
            $field->type() === FilterFieldType::IdList && in_array($operator, [FilterOperator::GreaterOrEqual, FilterOperator::LessOrEqual, FilterOperator::Between], true) => $isNumber,
            $field->type() === FilterFieldType::IdList => fn (mixed $value): bool => preg_match('/^\s*\d+(\s*,\s*\d+)*\s*$/', (string) $value) === 1,
            // A choice list with nothing to offer (a project without
            // trackers, say) can still only be keyed by ids.
            $field->type() === FilterFieldType::Select, $field->type() === FilterFieldType::Integer, $operator === FilterOperator::InTheLastDays => $isNumber,
            $field->type() === FilterFieldType::Date => fn (mixed $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?$/', (string) $value) === 1,
            $field->type() === FilterFieldType::Boolean => fn (mixed $value): bool => in_array((string) $value, ['0', '1'], true),
            default => fn (mixed $value): bool => true,
        };

        return array_values(array_filter($values, $isWellFormed));
    }
}
