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

    /*
     * Redmine's relative date operators, spelled with its own codes. They compare whole days against
     * today as the viewer sees it, and the week starts where the site's start_of_week says
     * (RelativeDateRange).
     */
    case Today = 't';
    case Yesterday = 'ld';
    case Tomorrow = 'nd';
    case ThisWeek = 'w';
    case LastWeek = 'lw';
    case LastTwoWeeks = 'l2w';
    case NextWeek = 'nw';
    case ThisMonth = 'm';
    case LastMonth = 'lm';
    case NextMonth = 'nm';
    case ThisYear = 'y';
    case DaysAgo = 't-';
    case MoreThanDaysAgo = '<t-';
    case InDays = 't+';
    case InMoreThanDays = '>t+';
    case InTheNextDays = '<t+';

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
            self::Today => __('今日'),
            self::Yesterday => __('昨日'),
            self::Tomorrow => __('明日'),
            self::ThisWeek => __('今週'),
            self::LastWeek => __('先週'),
            self::LastTwoWeeks => __('過去2週間'),
            self::NextWeek => __('来週'),
            self::ThisMonth => __('今月'),
            self::LastMonth => __('先月'),
            self::NextMonth => __('来月'),
            self::ThisYear => __('今年'),
            self::DaysAgo => __('n日前'),
            self::MoreThanDaysAgo => __('n日より前'),
            self::InDays => __('n日後'),
            self::InMoreThanDays => __('n日より後'),
            self::InTheNextDays => __('今後n日以内'),
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
        return ! in_array($this, [self::IsEmpty, self::IsNotEmpty, self::AnyOpenIssues, self::NoOpenIssues], true) && ! $this->isRelativePeriod();
    }

    /**
     * A named period relative to today (today, this week, last month ...), which takes no value.
     */
    public function isRelativePeriod(): bool
    {
        return in_array($this, [
            self::Today, self::Yesterday, self::Tomorrow, self::ThisWeek, self::LastWeek, self::LastTwoWeeks,
            self::NextWeek, self::ThisMonth, self::LastMonth, self::NextMonth, self::ThisYear,
        ], true);
    }

    /**
     * Whether the value is a number of days from today (in the last 7 days, 3 days ago ...).
     */
    public function takesDays(): bool
    {
        return in_array($this, [self::InTheLastDays, self::DaysAgo, self::MoreThanDaysAgo, self::InDays, self::InMoreThanDays, self::InTheNextDays], true);
    }

    /**
     * Operators a date field offers: the exact ones, then Redmine's relative ones.
     *
     * @return array<int, self>
     */
    public static function dateChoices(bool $withEmptiness = true): array
    {
        return [
            self::Equals, self::GreaterOrEqual, self::LessOrEqual, self::Between,
            self::Today, self::Yesterday, self::Tomorrow, self::ThisWeek, self::LastWeek, self::LastTwoWeeks, self::NextWeek,
            self::ThisMonth, self::LastMonth, self::NextMonth, self::ThisYear,
            self::InTheLastDays, self::DaysAgo, self::MoreThanDaysAgo, self::InTheNextDays, self::InDays, self::InMoreThanDays,
            ...($withEmptiness ? [self::IsEmpty, self::IsNotEmpty] : []),
        ];
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
