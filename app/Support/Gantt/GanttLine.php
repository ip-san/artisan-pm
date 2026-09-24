<?php

declare(strict_types=1);

namespace App\Support\Gantt;

use App\Models\Project;
use App\Models\Version;

/**
 * One row of an exported Gantt chart (PDF/PNG): a project header, an issue
 * or a milestone, with its indent and the label the export prints.
 */
final readonly class GanttLine
{
    public const string PROJECT = 'project';

    public const string ISSUE = 'issue';

    public const string VERSION = 'version';

    private function __construct(
        public string $kind,
        public int $depth,
        public string $label,
        public ?GanttRow $row = null,
        public ?Version $version = null,
        public int $versionPercent = 0,
    ) {}

    public static function project(Project $project, int $depth): self
    {
        return new self(self::PROJECT, $depth, $project->name);
    }

    public static function issue(GanttRow $row, int $depth): self
    {
        return new self(self::ISSUE, $depth, "{$row->trackerName} #{$row->id}: {$row->subject}", row: $row);
    }

    public static function version(Version $version, int $depth): self
    {
        return new self(
            self::VERSION,
            $depth,
            $version->name,
            version: $version,
            versionPercent: (int) round($version->asSeenBy(auth()->user())->completedPercent()),
        );
    }
}
