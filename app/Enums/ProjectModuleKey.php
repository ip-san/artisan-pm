<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Feature modules that can be toggled on/off per project. Mirrors Redmine's
 * per-project module list; permissions declared against a module in the
 * PermissionRegistry are only in effect while that module is enabled.
 */
enum ProjectModuleKey: string
{
    case IssueTracking = 'issue_tracking';
    case TimeTracking = 'time_tracking';
    case News = 'news';
    case Documents = 'documents';
    case Files = 'files';
    case Wiki = 'wiki';
    case Repository = 'repository';
    case Boards = 'boards';
    case Calendar = 'calendar';
    case Gantt = 'gantt';

    /**
     * Modules enabled by default when a project is created.
     *
     * @return array<self>
     */
    public static function defaults(): array
    {
        return [
            self::IssueTracking,
            self::TimeTracking,
            self::News,
            self::Documents,
            self::Files,
            self::Wiki,
            self::Repository,
            self::Boards,
            self::Calendar,
            self::Gantt,
        ];
    }
}
