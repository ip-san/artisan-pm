<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\FilterOperator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Redmine's word matching, shared by the text filters (Query#sql_contains)
 * and the search (Redmine::Search::Tokenizer): the typed text is split into
 * words, and every comparison ignores case — PostgreSQL's LIKE does not, so
 * it gets ILIKE there, as Redmine::Database.like does.
 *
 * A leading-wildcard pattern can't use a b-tree index whether it is LIKE or
 * ILIKE, so switching to ILIKE doesn't change which plans are possible.
 */
final class TextMatch
{
    /**
     * Redmine::Search::Tokenizer#tokens: `hello "bye bye"` gives `hello`
     * and `bye bye`; words of one character are dropped unless they are a
     * kanji/hanzi, and no more than five are kept.
     *
     * @return array<int, string>
     */
    public static function tokens(string $text): array
    {
        preg_match_all('/"[^"]+"|[^\p{Zs}\s]+/u', $text, $matches);

        $tokens = array_map(
            fn (string $token): string => (string) preg_replace('/\A"\p{Zs}*|\p{Zs}*"\z/u', '', $token),
            $matches[0],
        );

        $tokens = array_filter(
            array_unique($tokens),
            fn (string $token): bool => mb_strlen($token) > 1 || preg_match('/\p{Han}/u', $token) === 1,
        );

        return array_slice(array_values($tokens), 0, 5);
    }

    /**
     * Whether $operator is one of the word-matching text operators.
     */
    public static function handles(FilterOperator $operator): bool
    {
        return in_array($operator, [
            FilterOperator::Contains,
            FilterOperator::NotContains,
            FilterOperator::ContainsAny,
            FilterOperator::StartsWith,
            FilterOperator::EndsWith,
        ], true);
    }

    /**
     * Query.tokenized_like_conditions: "contains" needs every word,
     * "contains any" one of them, "does not contain" none of them, and
     * "starts with"/"ends with" one word at the start/end. Text that
     * yields no word is matched as typed.
     *
     * @param  Builder<*>|QueryBuilder  $query
     */
    public static function apply(Builder|QueryBuilder $query, string $column, FilterOperator $operator, string $text): void
    {
        $tokens = self::tokens($text);
        $tokens = $tokens === [] ? [$text] : $tokens;

        [$prefix, $suffix, $anyWord] = match ($operator) {
            FilterOperator::StartsWith => ['', '%', true],
            FilterOperator::EndsWith => ['%', '', true],
            FilterOperator::ContainsAny => ['%', '%', true],
            default => ['%', '%', false],
        };

        $like = ($operator === FilterOperator::NotContains ? 'not ' : '').self::likeOperator($query);

        $query->where(function ($words) use ($tokens, $column, $like, $prefix, $suffix, $anyWord): void {
            foreach ($tokens as $index => $token) {
                $words->where($column, $like, $prefix.self::escape($token).$suffix, boolean: $anyWord && $index > 0 ? 'or' : 'and');
            }
        });
    }

    /**
     * @param  Builder<*>|QueryBuilder  $query
     */
    public static function likeOperator(Builder|QueryBuilder $query): string
    {
        return $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * A typed % or _ matches itself rather than acting as a wildcard.
     */
    public static function escape(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
