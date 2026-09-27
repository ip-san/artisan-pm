<?php

declare(strict_types=1);

namespace App\Support\Ui;

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\IssueStatus;

/**
 * Colour tones for status and priority badges, so an issue's state reads at a glance.
 *
 * Priorities follow Redmine's IssuePriority#position_name: those above the default are "high"
 * and the top one "highest"; the default and below stay quiet. Redmine statuses carry no
 * colour, so a status is toned by its place in the workflow order: the first open status is
 * new, later open ones are in progress, and closed ones are done.
 */
final class IssueBadges
{
    public const string Neutral = 'neutral';

    public const string Brand = 'brand';

    public const string Warning = 'warning';

    public const string Danger = 'danger';

    public const string Success = 'success';

    public static function statusTone(IssueStatus $status): string
    {
        if ($status->is_closed) {
            return self::Success;
        }

        $firstOpenPosition = once(fn () => IssueStatus::query()->where('is_closed', false)->min('position'));

        return $status->position <= $firstOpenPosition ? self::Brand : self::Warning;
    }

    /**
     * @return 'lowest'|'low'|'default'|'high'|'highest'
     */
    public static function priorityLevel(Enumeration $priority): string
    {
        /** @var array{default: ?int, lowest: ?int, highest: ?int} $bounds */
        $bounds = once(function (): array {
            $active = Enumeration::query()->ofType(EnumerationType::IssuePriority)->where('active', true)->whereNull('project_id')->get(['position', 'is_default']);

            return [
                'default' => $active->firstWhere('is_default', true)?->position,
                'lowest' => $active->min('position'),
                'highest' => $active->max('position'),
            ];
        });
        $default = $bounds['default'] ?? $bounds['lowest'];

        return match (true) {
            $priority->position === $default => 'default',
            $priority->position < $default => $priority->position <= $bounds['lowest'] ? 'lowest' : 'low',
            $priority->position >= $bounds['highest'] => 'highest',
            default => 'high',
        };
    }

    public static function priorityTone(Enumeration $priority): string
    {
        return match (self::priorityLevel($priority)) {
            'highest' => self::Danger,
            'high' => self::Warning,
            default => self::Neutral,
        };
    }
}
