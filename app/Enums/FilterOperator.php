<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Operators a saved/ad-hoc Query filter row can use. Kept as a fixed enum
 * with shared apply-logic (App\Support\Query\FilterOperatorApplier) rather
 * than a pluggable registry — there's no plugin system yet to register
 * additional operators into, so a registry would be premature abstraction.
 */
enum FilterOperator: string
{
    case Equals = '=';
    case NotEquals = '!';
    case In = 'in';
    case NotIn = '!in';
    case Contains = '~';
    case NotContains = '!~';
    case IsEmpty = 'empty';
    case IsNotEmpty = 'not_empty';
    case GreaterOrEqual = '>=';
    case LessOrEqual = '<=';
    case Between = '><';
    case InTheLastDays = 'last_days';

    public function label(): string
    {
        return match ($this) {
            self::Equals => 'が次の値',
            self::NotEquals => 'が次の値ではない',
            self::In => 'がいずれかに含まれる',
            self::NotIn => 'がいずれにも含まれない',
            self::Contains => 'に次を含む',
            self::NotContains => 'に次を含まない',
            self::IsEmpty => 'が未設定',
            self::IsNotEmpty => 'が設定されている',
            self::GreaterOrEqual => 'が次の値以上',
            self::LessOrEqual => 'が次の値以下',
            self::Between => 'が次の範囲内',
            self::InTheLastDays => '過去n日以内',
        };
    }

    /**
     * Whether this operator needs any values typed in (IsEmpty/IsNotEmpty
     * don't take a value).
     */
    public function requiresValue(): bool
    {
        return ! in_array($this, [self::IsEmpty, self::IsNotEmpty], true);
    }
}
