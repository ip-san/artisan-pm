<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Models\Group;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * Redmine's assignee_dropdown_display_format: how an assignee select
     * lays out users and groups — `users_then_groups` (default),
     * `groups_then_users` or `users_by_group` (the groups, then each group's
     * users, then the users in no listed group). Without groups the users
     * are listed plainly (a single unlabelled section).
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Group>  $groups
     * @return list<array{label: ?string, options: list<array{value: string, name: string}>}>
     */
    public static function optionGroups(Collection $users, Collection $groups): array
    {
        $userOptions = fn (Collection $list) => $list->map(fn (User $user) => ['value' => (string) $user->id, 'name' => $user->displayName()])->values()->all();
        $groupOptions = $groups->map(fn (Group $group) => ['value' => self::forGroup($group), 'name' => $group->name])->values()->all();

        if ($groups->isEmpty()) {
            return [['label' => null, 'options' => $userOptions($users)]];
        }

        $sections = match (self::displayFormat()) {
            'groups_then_users' => [
                ['label' => __('グループ'), 'options' => $groupOptions],
                ['label' => __('ユーザー'), 'options' => $userOptions($users)],
            ],
            'users_by_group' => self::usersByGroup($users, $groups, $groupOptions, $userOptions),
            default => [
                ['label' => __('ユーザー'), 'options' => $userOptions($users)],
                ['label' => __('グループ'), 'options' => $groupOptions],
            ],
        };

        return array_values(array_filter($sections, fn (array $section) => $section['options'] !== []));
    }

    /**
     * @return array<string, string>
     */
    public static function displayFormats(): array
    {
        return [
            'users_then_groups' => __('ユーザー → グループ'),
            'groups_then_users' => __('グループ → ユーザー'),
            'users_by_group' => __('グループ別'),
        ];
    }

    public static function displayFormat(): string
    {
        $format = (string) Setting::get('assignee_dropdown_display_format', 'users_then_groups');

        return array_key_exists($format, self::displayFormats()) ? $format : 'users_then_groups';
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Group>  $groups
     * @param  list<array{value: string, name: string}>  $groupOptions
     * @param  Closure(Collection<int, User>): list<array{value: string, name: string}>  $userOptions
     * @return list<array{label: ?string, options: list<array{value: string, name: string}>}>
     */
    private static function usersByGroup(Collection $users, Collection $groups, array $groupOptions, Closure $userOptions): array
    {
        $memberIds = DB::table('group_user')->whereIn('group_id', $groups->pluck('id'))->get(['group_id', 'user_id'])
            ->groupBy('group_id')
            ->map(fn (Collection $rows) => $rows->pluck('user_id')->map(fn ($id) => (int) $id));

        $sections = [['label' => __('グループ'), 'options' => $groupOptions]];
        $grouped = collect();

        foreach ($groups as $group) {
            $groupUsers = $users->filter(fn (User $user) => ($memberIds[$group->id] ?? collect())->contains($user->id));
            $grouped = $grouped->merge($groupUsers->pluck('id'));
            $sections[] = ['label' => $group->name, 'options' => $userOptions($groupUsers)];
        }

        $sections[] = ['label' => __('ユーザー'), 'options' => $userOptions($users->reject(fn (User $user) => $grouped->contains($user->id)))];

        return $sections;
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
