<?php

declare(strict_types=1);

namespace App\Support\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The few raw SQL fragments whose spelling differs between the databases
 * the app runs on (PostgreSQL, MySQL 8 / MariaDB 10.x on shared hosting,
 * SQLite), like TextMatch::likeOperator() does for case-insensitive LIKE.
 */
final class SqlDialect
{
    /**
     * `$expression` as a string. MySQL and MariaDB only CAST to CHAR (a
     * `CAST(... AS VARCHAR)` is a syntax error there), while PostgreSQL's
     * CHAR means character(1) and would cut the value to one character.
     *
     * @param  Builder<*>|QueryBuilder|Connection  $source
     */
    public static function castAsText(Builder|QueryBuilder|Connection $source, string $expression): string
    {
        return self::isMysqlFamily($source)
            ? "CAST({$expression} AS CHAR)"
            : "CAST({$expression} AS VARCHAR)";
    }

    /**
     * @param  Builder<*>|QueryBuilder|Connection  $source
     */
    public static function isMysqlFamily(Builder|QueryBuilder|Connection $source): bool
    {
        $connection = $source instanceof Connection ? $source : $source->getConnection();

        return $connection instanceof Connection && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
