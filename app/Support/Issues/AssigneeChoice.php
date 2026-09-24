<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Models\Group;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
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
     * When $involved is given (editing a single issue — Redmine's
     * principals_options_for_select), an "作成者 / 直前担当者" section comes
     * first; an involved principal that is not otherwise offered is shown
     * disabled.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Group>  $groups
     * @param  Collection<int, User|Group>|null  $involved
     * @return list<array{label: ?string, options: list<array{value: string, name: string, disabled?: bool}>}>
     */
    public static function optionGroups(Collection $users, Collection $groups, ?Collection $involved = null): array
    {
        $userOptions = fn (Collection $list) => $list->map(fn (User $user) => ['value' => (string) $user->id, 'name' => $user->displayName()])->values()->all();
        $groupOptions = $groups->map(fn (Group $group) => ['value' => self::forGroup($group), 'name' => $group->name])->values()->all();
        $involvedSection = self::involvedSection($users, $groups, $involved ?? collect());

        if ($groups->isEmpty() && $involvedSection === null) {
            return [['label' => null, 'options' => $userOptions($users)]];
        }

        if ($groups->isEmpty()) {
            return array_values(array_filter([$involvedSection, ['label' => __('ユーザー'), 'options' => $userOptions($users)]], fn (array $section) => $section['options'] !== []));
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

        if ($involvedSection !== null) {
            array_unshift($sections, $involvedSection);
        }

        return array_values(array_filter($sections, fn (array $section) => $section['options'] !== []));
    }

    /**
     * Redmine's "involved principals" of an issue being edited: its author
     * and its prior assignee (the old value of the latest assignee change,
     * a user or a group — Issue#prior_assigned_to).
     *
     * @return Collection<int, User|Group>
     */
    public static function involvedPrincipals(Issue $issue): Collection
    {
        $principals = collect([$issue->author]);

        $prior = DB::table('journal_details')
            ->join('journals', 'journals.id', '=', 'journal_details.journal_id')
            ->where('journals.issue_id', $issue->id)
            ->where('journal_details.property', 'attr')
            ->whereIn('journal_details.prop_key', ['assigned_to_id', 'assigned_to_group_id'])
            ->whereNotNull('journal_details.old_value')
            ->where('journal_details.old_value', '<>', '')
            ->orderByDesc('journals.id')
            ->orderByDesc('journal_details.id')
            ->first(['journal_details.prop_key', 'journal_details.old_value']);

        if ($prior !== null && ctype_digit((string) $prior->old_value)) {
            $principals->push($prior->prop_key === 'assigned_to_group_id'
                ? Group::query()->find((int) $prior->old_value)
                : User::query()->find((int) $prior->old_value));
        }

        return $principals->filter()->unique(fn (User|Group $principal) => $principal::class.':'.$principal->id)->values();
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Group>  $groups
     * @param  Collection<int, User|Group>  $involved
     * @return array{label: string, options: list<array{value: string, name: string, disabled: bool}>}|null
     */
    private static function involvedSection(Collection $users, Collection $groups, Collection $involved): ?array
    {
        if ($involved->isEmpty()) {
            return null;
        }

        $options = $involved->map(fn (User|Group $principal) => $principal instanceof Group
            ? ['value' => self::forGroup($principal), 'name' => $principal->name, 'disabled' => ! $groups->contains('id', $principal->id)]
            : ['value' => (string) $principal->id, 'name' => $principal->displayName(), 'disabled' => ! $users->contains('id', $principal->id)]
        )->values()->all();

        return ['label' => __('作成者 / 直前担当者'), 'options' => $options];
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
     * A saving hook for a user/group assignee column pair (a project's
     * default assignee, a category's): setting one clears the other, as
     * Issue does for its own assignee.
     */
    public static function keepSingle(Model $model, string $userColumn, string $groupColumn): void
    {
        if ($model->getAttribute($userColumn) === null || $model->getAttribute($groupColumn) === null) {
            return;
        }

        if ($model->isDirty($groupColumn) && ! $model->isDirty($userColumn)) {
            $model->setAttribute($userColumn, null);
        } else {
            $model->setAttribute($groupColumn, null);
        }
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
