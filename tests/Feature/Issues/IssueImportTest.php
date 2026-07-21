<?php

use App\Enums\ImportStatus;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueImport;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function importMember(Project $project): User
{
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function csvFile(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

test('uploading a csv auto-detects headers and lets them be mapped', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $user = importMember($project);

    $csv = "subject,description\nFirst row,Some text\n";

    $component = Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv));

    expect($component->get('headers'))->toBe(['subject', 'description'])
        ->and($component->get('mapping')['subject'])->toBe('subject');
});

test('starting an import dispatches a job that creates issues from csv rows', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $csv = "件名,説明\nログインできない,詳細な説明です\nダークモード対応,別の説明\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', '件名')
        ->set('mapping.description', '説明')
        ->call('startImport')
        ->assertRedirect();

    $import = IssueImport::firstOrFail();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->imported_count)->toBe(2)
        ->and($import->failed_count)->toBe(0)
        ->and(Issue::where('subject', 'ログインできない')->exists())->toBeTrue()
        ->and(Issue::where('subject', 'ダークモード対応')->where('description', '別の説明')->exists())->toBeTrue();
});

test('a row missing its mapped subject is recorded as a failure without stopping the rest of the import', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $csv = "subject\n\nValid subject\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->call('startImport');

    $import = IssueImport::firstOrFail();

    expect($import->imported_count)->toBe(1)
        ->and($import->failed_count)->toBe(1)
        ->and($import->errors)->toHaveCount(1)
        ->and(Issue::where('subject', 'Valid subject')->exists())->toBeTrue();
});

test('rows resolve tracker, status, and priority by name and fall back to project defaults', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $defaultTracker = Tracker::factory()->create(['name' => 'Bug']);
    $namedTracker = Tracker::factory()->create(['name' => 'Feature']);
    $project->trackers()->attach([$defaultTracker->id, $namedTracker->id]);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $csv = "subject,tracker\nUses default tracker,\nUses named tracker,Feature\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->set('mapping.tracker', 'tracker')
        ->call('startImport');

    $defaultRow = Issue::where('subject', 'Uses default tracker')->firstOrFail();
    $namedRow = Issue::where('subject', 'Uses named tracker')->firstOrFail();

    expect($defaultRow->tracker_id)->toBe($defaultTracker->id)
        ->and($namedRow->tracker_id)->toBe($namedTracker->id);
});

test('a user without add_issues cannot start an import', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)->test('issues.import', ['project' => $project])->assertForbidden();
});

test('the import status page shows progress and errors once finished', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = importMember($project);

    $csv = "subject\nOnly row\n";

    Livewire::actingAs($user)
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', csvFile('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->call('startImport');

    $import = IssueImport::firstOrFail();

    Livewire::actingAs($user)
        ->test('issues.import-status', ['project' => $project, 'import' => $import])
        ->assertSee('完了しました')
        ->assertSee('1');
});
