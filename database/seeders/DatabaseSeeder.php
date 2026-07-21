<?php

namespace Database\Seeders;

use App\Enums\EnumerationType;
use App\Enums\ProjectModuleKey;
use App\Enums\RoleBuiltin;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\WorkflowTransition;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Administrator',
            'email' => 'admin@example.com',
        ]);

        $this->seedBuiltinRoles();
        $this->seedDefaultRoles();
        $this->seedTrackers();
        $this->seedIssueStatuses();
        $this->seedEnumerations();
        $this->seedWorkflow();

        $project = Project::factory()->create([
            'name' => 'Demo Project',
            'identifier' => 'demo-project',
        ]);
        $project->syncModules(ProjectModuleKey::defaults());
        $project->trackers()->attach(Tracker::all());

        $member = $project->members()->create(['user_id' => $admin->id]);
        $member->roles()->attach(Role::where('name', 'Manager')->first());

        $this->seedDemoIssues($project, $admin);
    }

    private function seedBuiltinRoles(): void
    {
        Role::query()->firstOrCreate(
            ['builtin' => RoleBuiltin::Anonymous->value],
            ['name' => 'Anonymous', 'permissions' => ['view_project'], 'position' => 1]
        );

        Role::query()->firstOrCreate(
            ['builtin' => RoleBuiltin::NonMember->value],
            ['name' => 'Non member', 'permissions' => ['view_project'], 'position' => 2]
        );
    }

    private function seedDefaultRoles(): void
    {
        $roles = [
            'Manager' => [
                'view_project', 'edit_project', 'close_project', 'delete_project', 'select_project_modules',
                'manage_members', 'add_subprojects', 'manage_versions', 'manage_categories',
                'view_issues', 'add_issues', 'edit_issues', 'delete_issues', 'manage_issue_relations', 'add_issue_watchers',
            ],
            'Developer' => [
                'view_project', 'manage_versions',
                'view_issues', 'add_issues', 'edit_issues', 'add_issue_watchers',
            ],
            'Reporter' => [
                'view_project', 'view_issues', 'add_issues', 'add_issue_watchers',
            ],
        ];

        $position = 3;
        foreach ($roles as $name => $permissions) {
            Role::query()->updateOrCreate(
                ['name' => $name],
                ['permissions' => $permissions, 'position' => $position++]
            );
        }
    }

    private function seedTrackers(): void
    {
        foreach (['Bug', 'Feature', 'Support'] as $name) {
            Tracker::query()->firstOrCreate(['name' => $name]);
        }
    }

    private function seedIssueStatuses(): void
    {
        $statuses = [
            'New' => false,
            'In Progress' => false,
            'Resolved' => false,
            'Feedback' => false,
            'Closed' => true,
            'Rejected' => true,
        ];

        foreach ($statuses as $name => $isClosed) {
            IssueStatus::query()->firstOrCreate(['name' => $name], ['is_closed' => $isClosed]);
        }
    }

    private function seedEnumerations(): void
    {
        $priorities = ['Low', 'Normal', 'High', 'Urgent', 'Immediate'];
        foreach ($priorities as $i => $name) {
            Enumeration::query()->firstOrCreate(
                ['type' => EnumerationType::IssuePriority->value, 'name' => $name],
                ['is_default' => $name === 'Normal']
            );
        }

        $activities = ['Design', 'Development', 'Testing'];
        foreach ($activities as $name) {
            Enumeration::query()->firstOrCreate(
                ['type' => EnumerationType::TimeEntryActivity->value, 'name' => $name],
                ['is_default' => $name === 'Development']
            );
        }
    }

    /**
     * Every tracker shares the same simple New -> In Progress -> Resolved ->
     * Closed flow for Manager/Developer/Reporter, with Manager additionally
     * able to reject or reopen closed issues.
     */
    private function seedWorkflow(): void
    {
        $statuses = IssueStatus::query()->pluck('id', 'name');
        $manager = Role::where('name', 'Manager')->first();
        $developer = Role::where('name', 'Developer')->first();
        $reporter = Role::where('name', 'Reporter')->first();

        $commonTransitions = [
            ['New', 'In Progress'],
            ['In Progress', 'Resolved'],
            ['Resolved', 'In Progress'],
            ['Resolved', 'Closed'],
            ['Feedback', 'In Progress'],
        ];

        $managerOnlyTransitions = [
            ['New', 'Rejected'],
            ['In Progress', 'Rejected'],
            ['Closed', 'New'],
        ];

        foreach (Tracker::all() as $tracker) {
            foreach ([$manager, $developer, $reporter] as $role) {
                foreach ($commonTransitions as [$from, $to]) {
                    WorkflowTransition::query()->firstOrCreate([
                        'tracker_id' => $tracker->id,
                        'role_id' => $role->id,
                        'old_status_id' => $statuses[$from],
                        'new_status_id' => $statuses[$to],
                    ]);
                }
            }

            foreach ($managerOnlyTransitions as [$from, $to]) {
                WorkflowTransition::query()->firstOrCreate([
                    'tracker_id' => $tracker->id,
                    'role_id' => $manager->id,
                    'old_status_id' => $statuses[$from],
                    'new_status_id' => $statuses[$to],
                ]);
            }
        }
    }

    private function seedDemoIssues(Project $project, User $author): void
    {
        $newStatus = IssueStatus::where('name', 'New')->firstOrFail();
        $normalPriority = Enumeration::query()->ofType(EnumerationType::IssuePriority)->where('name', 'Normal')->firstOrFail();
        $bug = Tracker::where('name', 'Bug')->firstOrFail();
        $feature = Tracker::where('name', 'Feature')->firstOrFail();

        Issue::factory()->for($project)->create([
            'tracker_id' => $bug->id,
            'status_id' => $newStatus->id,
            'priority_id' => $normalPriority->id,
            'author_id' => $author->id,
            'subject' => 'ログインページでエラーが発生する',
        ]);

        Issue::factory()->for($project)->create([
            'tracker_id' => $feature->id,
            'status_id' => $newStatus->id,
            'priority_id' => $normalPriority->id,
            'author_id' => $author->id,
            'subject' => 'ダークモードに対応する',
        ]);
    }
}
