<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Models\Group;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * One value for an assignee picker that offers users and groups together
 * (Redmine's Principal select). Users and groups have separate id spaces
 * here, so a user keeps its plain id and a group is written `group:<id>`.
 */
final class AssigneeChoice
{
    public const string GROUP_PREFIX = 'group:';

    public static function encode(?int $userId, ?int $groupId): string
    {
        return match (true) {
            $groupId !== null => self::GROUP_PREFIX.$groupId,
            $userId !== null => (string) $userId,
            default => '',
        };
    }

    public static function forGroup(Group $group): string
    {
        return self::GROUP_PREFIX.$group->id;
    }

    /**
     * @return array{assigned_to_id: ?int, assigned_to_group_id: ?int}
     */
    public static function decode(?string $value): array
    {
        $value = trim((string) $value);

        if (str_starts_with($value, self::GROUP_PREFIX)) {
            $groupId = substr($value, strlen(self::GROUP_PREFIX));

            return ['assigned_to_id' => null, 'assigned_to_group_id' => ctype_digit($groupId) ? (int) $groupId : null];
        }

        return ['assigned_to_id' => ctype_digit($value) ? (int) $value : null, 'assigned_to_group_id' => null];
    }

    /**
     * The groups an assignee picker offers in $project: its assignable
     * groups while group assignment is enabled, plus $currentGroupId (an
     * existing assignment stays shown and selectable when the setting is
     * off or the group lost its role).
     *
     * @return Collection<int, Group>
     */
    public static function groupOptions(Project $project, ?int $currentGroupId = null): Collection
    {
        $groups = Issue::groupAssignmentEnabled() ? $project->assignableGroups() : collect();

        if ($currentGroupId !== null && ! $groups->contains('id', $currentGroupId)) {
            $current = Group::query()->find($currentGroupId);

            if ($current !== null) {
                $groups = $groups->push($current)->sortBy('name')->values();
            }
        }

        return $groups;
    }

    /**
     * Whether $groupId may be saved as the assignee: unchanged from
     * $currentGroupId, or one of the project's assignable groups while group
     * assignment is enabled.
     */
    public static function allowsGroup(Project $project, int $groupId, ?int $currentGroupId = null): bool
    {
        if ($groupId === $currentGroupId) {
            return true;
        }

        return Issue::groupAssignmentEnabled() && $project->assignableGroups()->contains('id', $groupId);
    }
}
