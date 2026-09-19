<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Enums\EnumerationType;
use App\Enums\RoleBuiltin;
use App\Models\CustomField;
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

        $this->call(DefaultConfigurationSeeder::class);
        $this->seedCustomFields();

        // ProjectFactory::configure() already syncs the default modules via
        // its afterCreating hook, matching real project-creation behavior.
        $project = Project::factory()->create([
            'name' => 'Demo Project',
            'identifier' => 'demo-project',
        ]);
        $project->trackers()->attach(Tracker::all());

        $member = $project->members()->create(['user_id' => $admin->id]);
        $member->roles()->attach(Role::where('name', 'Manager')->first());

        $this->seedDemoIssues($project, $admin);
    }

    private function seedCustomFields(): void
    {
        $bug = Tracker::where('name', 'Bug')->firstOrFail();

        $severity = CustomField::query()->firstOrCreate(
            ['name' => 'Severity', 'customized_type' => CustomizableType::Issue->value],
            ['field_format' => CustomFieldFormat::List->value, 'possible_values' => ['Low', 'Medium', 'High', 'Critical']]
        );
        $severity->trackers()->syncWithoutDetaching($bug);

        $browser = CustomField::query()->firstOrCreate(
            ['name' => 'Affected browser', 'customized_type' => CustomizableType::Issue->value],
            ['field_format' => CustomFieldFormat::String->value]
        );
        $browser->trackers()->syncWithoutDetaching($bug);
    }

    private function seedDemoIssues(Project $project, User $author): void
    {
        $newStatus = IssueStatus::where('name', 'New')->firstOrFail();
        $normalPriority = Enumeration::query()->ofType(EnumerationType::IssuePriority)->where('name', 'Normal')->firstOrFail();
        $trackers = Tracker::query()->whereIn('name', ['Bug', 'Feature'])->pluck('id', 'name');

        $bugIssue = Issue::factory()->for($project)->create([
            'tracker_id' => $trackers['Bug'],
            'status_id' => $newStatus->id,
            'priority_id' => $normalPriority->id,
            'author_id' => $author->id,
            'subject' => 'ログインページでエラーが発生する',
        ]);

        $bugIssue->setCustomFieldValues([
            CustomField::where('name', 'Severity')->value('id') => 'High',
            CustomField::where('name', 'Affected browser')->value('id') => 'Safari',
        ]);

        Issue::factory()->for($project)->create([
            'tracker_id' => $trackers['Feature'],
            'status_id' => $newStatus->id,
            'priority_id' => $normalPriority->id,
            'author_id' => $author->id,
            'subject' => 'ダークモードに対応する',
        ]);
    }
}
