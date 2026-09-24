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
    case ContainsAny = '*~';
    case StartsWith = '^';
    case EndsWith = '$';
    case IsEmpty = 'empty';
    case IsNotEmpty = 'not_empty';
    case GreaterOrEqual = '>=';
    case LessOrEqual = '<=';
    case Between = '><';
    case InTheLastDays = 'last_days';

    /**
     * Relation filters only (Redmine's :relation operators): related to
     * an issue that is still open / to none that is.
     */
    case AnyOpenIssues = '*o';
    case NoOpenIssues = '!o';

    /**
     * Relation filters only: related to an issue in the chosen project, to
     * one outside it, or to none in it.
     */
    case AnyIssuesInProject = '=p';
    case AnyIssuesNotInProject = '=!p';
    case NoIssuesInProject = '!p';

    public function label(): string
    {
        return match ($this) {
            self::Equals => __('が次の値'),
            self::NotEquals => __('が次の値ではない'),
            self::In => __('がいずれかに含まれる'),
            self::NotIn => __('がいずれにも含まれない'),
            self::Contains => __('に次を含む'),
            self::NotContains => __('に次を含まない'),
            self::ContainsAny => __('に次のいずれかを含む'),
            self::StartsWith => __('が次で始まる'),
            self::EndsWith => __('が次で終わる'),
            self::IsEmpty => __('が未設定'),
            self::IsNotEmpty => __('が設定されている'),
            self::GreaterOrEqual => __('が次の値以上'),
            self::LessOrEqual => __('が次の値以下'),
            self::Between => __('が次の範囲内'),
            self::InTheLastDays => __('過去n日以内'),
            self::AnyOpenIssues => __('が未完了の課題あり'),
            self::NoOpenIssues => __('が未完了の課題なし'),
            self::AnyIssuesInProject => __('が次のプロジェクトの課題あり'),
            self::AnyIssuesNotInProject => __('が次のプロジェクト以外の課題あり'),
            self::NoIssuesInProject => __('が次のプロジェクトの課題なし'),
        };
    }

    /**
     * Whether this operator needs any values typed in (IsEmpty/IsNotEmpty
     * and the open-issue relation operators don't take a value).
     */
    public function requiresValue(): bool
    {
        return ! in_array($this, [self::IsEmpty, self::IsNotEmpty, self::AnyOpenIssues, self::NoOpenIssues], true);
    }

    /**
     * Whether the value is a project rather than what the field itself
     * holds (the filter UI then offers the field's options, the projects).
     */
    public function takesProject(): bool
    {
        return in_array($this, [self::AnyIssuesInProject, self::AnyIssuesNotInProject, self::NoIssuesInProject], true);
    }
}
