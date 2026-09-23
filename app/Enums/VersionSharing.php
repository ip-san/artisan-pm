<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How widely a version can be assigned as an issue's target beyond its
 * own project — matches Redmine's Version::VERSION_SHARINGS.
 */
enum VersionSharing: string
{
    case None = 'none';
    case Descendants = 'descendants';
    case Hierarchy = 'hierarchy';
    case Tree = 'tree';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::None => __('共有しない'),
            self::Descendants => __('サブプロジェクトと共有'),
            self::Hierarchy => __('プロジェクト階層全体と共有'),
            self::Tree => __('プロジェクトツリー全体と共有'),
            self::System => __('全プロジェクトと共有'),
        };
    }
}
