<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Member;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Redmine's inherited project members (projects.inherit_members,
 * member_roles.inherited_from): a subproject that inherits members holds a
 * copy of every member_roles row of its parent, each pointing back at the
 * row it came from. Group members are copied as group rows; who is in the
 * group is still resolved when permissions are checked.
 *
 * Unlike Redmine, which applies each change as it happens from model
 * callbacks, sync() reconciles a project against its parent's current
 * rows, so calling it after any change (or twice) always ends in the same
 * state. The copies can only ever match what the parent grants: rows whose
 * source is gone are deleted (the inherited_from foreign key also removes
 * copies of copies), and members left without any role are removed.
 *
 * One member holds one row per role: when a subproject gives a member a
 * role directly that the parent also gives, the direct row wins and no
 * inherited copy is kept. Removing the direct role later brings the
 * inherited copy back on the next sync.
 */
final class MemberInheritance
{
    /**
     * Reconciles the project's inherited members with its parent's, then
     * does the same for each subproject that inherits from it.
     */
    public function sync(Project $project): void
    {
        DB::transaction(fn () => $this->reconcile($project));
    }

    /**
     * Reconciles the subprojects that inherit from the project, after its
     * own members or roles changed.
     */
    public function syncChildren(int $projectId): void
    {
        DB::transaction(function () use ($projectId): void {
            Project::query()
                ->where('parent_id', $projectId)
                ->where('inherit_members', true)
                ->get()
                ->each(fn (Project $child) => $this->reconcile($child));
        });
    }

    private function reconcile(Project $project): void
    {
        $sources = $project->inherit_members && $project->parent_id !== null
            ? DB::table('member_roles')
                ->join('members', 'members.id', '=', 'member_roles.member_id')
                ->where('members.project_id', $project->parent_id)
                ->get(['member_roles.id', 'member_roles.role_id', 'members.user_id', 'members.group_id'])
            : collect();

        $projectMemberIds = Member::query()->where('project_id', $project->id)->select('id');

        DB::table('member_roles')
            ->whereIn('member_id', $projectMemberIds)
            ->whereNotNull('inherited_from')
            ->whereNotIn('inherited_from', $sources->pluck('id'))
            ->delete();

        $copiedSourceIds = DB::table('member_roles')
            ->whereIn('member_id', $projectMemberIds)
            ->whereNotNull('inherited_from')
            ->pluck('inherited_from')
            ->map(fn ($id) => (int) $id);

        foreach ($sources as $source) {
            if ($copiedSourceIds->contains((int) $source->id)) {
                continue;
            }

            $member = Member::query()->firstOrCreate([
                'project_id' => $project->id,
                'user_id' => $source->user_id,
                'group_id' => $source->group_id,
            ]);

            $holdsRole = DB::table('member_roles')
                ->where('member_id', $member->id)
                ->where('role_id', $source->role_id)
                ->exists();

            if (! $holdsRole) {
                DB::table('member_roles')->insert([
                    'member_id' => $member->id,
                    'role_id' => $source->role_id,
                    'inherited_from' => $source->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Member::delete() (not a bulk delete) so its deleted hook clears
        // the project's default assignee and re-syncs further subprojects.
        Member::query()
            ->where('project_id', $project->id)
            ->whereDoesntHave('roles')
            ->get()
            ->each(fn (Member $member) => $member->delete());

        Project::query()
            ->where('parent_id', $project->id)
            ->where('inherit_members', true)
            ->get()
            ->each(fn (Project $child) => $this->reconcile($child));
    }
}
