<?php

declare(strict_types=1);

namespace App\Support\TimeReport;

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Illuminate\Support\Collection;

/**
 * One selectable row axis for the multi-dimensional time report — mirrors
 * Redmine::Helpers::TimeReport#available_criteria
 * (lib/redmine/helpers/time_report.rb), narrowed to native columns only.
 * Custom fields (Redmine also offers list/bool custom fields as criteria)
 * and the 'project' criterion (Redmine's cross-project report only, this
 * app's report is always scoped to a single project) are intentionally
 * out of scope, matching the narrower-grammar precedent already set by
 * IncomingMailService's keyword parsing.
 */
enum TimeReportCriterion: string
{
    case Status = 'status';
    case Version = 'version';
    case Category = 'category';
    case User = 'user';
    case Tracker = 'tracker';
    case Activity = 'activity';
    case Issue = 'issue';

    public function label(): string
    {
        return match ($this) {
            self::Status => 'ステータス',
            self::Version => 'バージョン',
            self::Category => 'カテゴリ',
            self::User => '担当者',
            self::Tracker => 'トラッカー',
            self::Activity => '作業分類',
            self::Issue => '課題',
        };
    }

    /**
     * The column this criterion groups by. Every 'issues.*' column
     * requires the report query to join the issues table first (see
     * self::requiresIssueJoin()) since it isn't a native time_entries column.
     */
    public function column(): string
    {
        return match ($this) {
            self::Status => 'issues.status_id',
            self::Version => 'issues.fixed_version_id',
            self::Category => 'issues.category_id',
            self::User => 'time_entries.user_id',
            self::Tracker => 'issues.tracker_id',
            self::Activity => 'time_entries.activity_id',
            self::Issue => 'time_entries.issue_id',
        };
    }

    /**
     * This native criterion as a report axis.
     */
    public function axis(): TimeReportAxis
    {
        return new TimeReportAxis(
            key: $this->value,
            label: $this->label(),
            expression: $this->column(),
            needsIssueJoin: in_array($this, [self::Status, self::Version, self::Category, self::Tracker], true),
            join: null,
            labels: fn (Collection $ids) => match ($this) {
                self::Status => IssueStatus::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                self::Version => Version::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                self::Category => IssueCategory::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                self::User => User::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                self::Tracker => Tracker::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                self::Activity => Enumeration::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
                self::Issue => Issue::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (Issue $issue) => [$issue->id => "#{$issue->id} {$issue->subject}"])->all(),
            },
        );
    }

    /**
     * @param  array<int, self>  $criteria
     */
    public static function requiresIssueJoin(array $criteria): bool
    {
        foreach ($criteria as $criterion) {
            if (in_array($criterion, [self::Status, self::Version, self::Category, self::Tracker], true)) {
                return true;
            }
        }

        return false;
    }
}
