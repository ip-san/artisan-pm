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
            self::Equals => __('が次の値'),
            self::NotEquals => __('が次の値ではない'),
            self::In => __('がいずれかに含まれる'),
            self::NotIn => __('がいずれにも含まれない'),
            self::Contains => __('に次を含む'),
            self::NotContains => __('に次を含まない'),
            self::IsEmpty => __('が未設定'),
            self::IsNotEmpty => __('が設定されている'),
            self::GreaterOrEqual => __('が次の値以上'),
            self::LessOrEqual => __('が次の値以下'),
            self::Between => __('が次の範囲内'),
            self::InTheLastDays => __('過去n日以内'),
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
