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
            self::None => '共有しない',
            self::Descendants => 'サブプロジェクトと共有',
            self::Hierarchy => 'プロジェクト階層全体と共有',
            self::Tree => 'プロジェクトツリー全体と共有',
            self::System => '全プロジェクトと共有',
        };
    }
}
