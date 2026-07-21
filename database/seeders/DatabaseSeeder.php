<?php

namespace Database\Seeders;

use App\Enums\EnumerationType;
use App\Enums\ProjectModuleKey;
use App\Enums\RoleBuiltin;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
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

        $project = Project::factory()->create([
            'name' => 'Demo Project',
            'identifier' => 'demo-project',
        ]);
        $project->syncModules(ProjectModuleKey::defaults());
        $project->trackers()->attach(Tracker::all());

        $member = $project->members()->create(['user_id' => $admin->id]);
        $member->roles()->attach(Role::where('name', 'Manager')->first());
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
            'Manager' => ['view_project', 'edit_project', 'close_project', 'delete_project', 'select_project_modules', 'manage_members', 'add_subprojects', 'manage_versions', 'manage_categories'],
            'Developer' => ['view_project', 'manage_versions'],
            'Reporter' => ['view_project'],
        ];

        $position = 3;
        foreach ($roles as $name => $permissions) {
            Role::query()->firstOrCreate(
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
}
