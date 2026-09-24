<?php

use App\Enums\ImportStatus;
use App\Enums\VersionStatus;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueImport;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Services\IncomingMailService;
use App\Services\IssueService;
use App\Support\Mail\ParsedIncomingMail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function creationDefaultsMember(Project $project, array $permissions = ['view_issues'], bool $assignable = true, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    Member::factory()->for($project)->for($user)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => $permissions, 'assignable' => $assignable]));

    return $user;
}

function creationDefaultsGroup(Project $project, bool $assignable = true): Group
{
    $group = Group::factory()->create();
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id])
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues'], 'assignable' => $assignable]));

    return $group;
}

/**
 * @return array{project: Project, tracker: Tracker, status: IssueStatus}
 */
function creationDefaultsProject(): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);

    return ['project' => $project, 'tracker' => $tracker, 'status' => $status];
}

/**
 * @param  array<string, mixed>  $payload
 */
function creationDefaultsApiIssue(Project $project, Tracker $tracker, array $payload = []): Issue
{
    Passport::actingAs(creationDefaultsMember($project, ['view_issues', 'add_issues']));

    $id = test()->postJson("/api/v1/projects/{$project->id}/issues", [
        'tracker_id' => $tracker->id,
        'priority_id' => Enumeration::query()->where('is_default', true)->value('id'),
        'subject' => 'Created through the API',
        ...$payload,
    ])->assertCreated()->json('data.id');

    return Issue::query()->findOrFail($id);
}

function creationDefaultsMailIssue(Project $project, Tracker $tracker, IssueStatus $status, string $body = 'Please fix it.'): Issue
{
    Setting::set('incoming_mail_default_project_id', $project->id);
    Setting::set('incoming_mail_default_tracker_id', $tracker->id);
    Setting::set('incoming_mail_default_status_id', $status->id);
    creationDefaultsMember($project, ['view_issues', 'add_issues'], attributes: ['email' => 'sender@example.com']);

    return app(IncomingMailService::class)->createIssueFromMail(
        new ParsedIncomingMail(subject: 'Broken', body: $body, fromEmail: 'sender@example.com'),
    );
}

/**
 * @param  array<string, string>  $mapping
 */
function creationDefaultsImport(Project $project, string $csv, array $mapping): void
{
    Storage::fake('local');

    $component = Livewire::actingAs(creationDefaultsMember($project, ['view_issues', 'add_issues', 'import_issues']))
        ->test('issues.import', ['project' => $project])
        ->set('csvFile', UploadedFile::fake()->createWithContent('issues.csv', $csv));

    foreach ($mapping as $field => $column) {
        $component->set("mapping.{$field}", $column);
    }

    $component->call('startImport');

    expect(IssueImport::query()->latest('id')->firstOrFail()->status)->toBe(ImportStatus::Completed);
}

test('an issue created through the api gets the project default assignee and version', function () {
    ['project' => $project, 'tracker' => $tracker] = creationDefaultsProject();
    $assignee = creationDefaultsMember($project);
    $version = Version::factory()->for($project)->create();
    $project->update(['default_assigned_to_id' => $assignee->id, 'default_version_id' => $version->id]);

    $issue = creationDefaultsApiIssue($project, $tracker);

    expect($issue->assigned_to_id)->toBe($assignee->id)
        ->and($issue->fixed_version_id)->toBe($version->id);
});

test('the api keeps a given assignee and version, and an explicit null version stays empty', function () {
    ['project' => $project, 'tracker' => $tracker] = creationDefaultsProject();
    $default = creationDefaultsMember($project);
    $chosen = creationDefaultsMember($project);
    $defaultVersion = Version::factory()->for($project)->create();
    $chosenVersion = Version::factory()->for($project)->create();
    $project->update(['default_assigned_to_id' => $default->id, 'default_version_id' => $defaultVersion->id]);

    $given = creationDefaultsApiIssue($project, $tracker, ['assigned_to_id' => $chosen->id, 'fixed_version_id' => $chosenVersion->id]);
    $cleared = creationDefaultsApiIssue($project, $tracker, ['fixed_version_id' => null]);

    expect($given->assigned_to_id)->toBe($chosen->id)
        ->and($given->fixed_version_id)->toBe($chosenVersion->id)
        ->and($cleared->fixed_version_id)->toBeNull()
        ->and($cleared->assigned_to_id)->toBe($default->id);
});

test('the api skips defaults that are no longer usable', function () {
    ['project' => $project, 'tracker' => $tracker] = creationDefaultsProject();
    $notAssignable = creationDefaultsMember($project, assignable: false);
    $closed = Version::factory()->for($project)->create(['status' => VersionStatus::Closed]);
    $project->update(['default_assigned_to_id' => $notAssignable->id, 'default_version_id' => $closed->id]);

    $issue = creationDefaultsApiIssue($project, $tracker);

    expect($issue->assigned_to_id)->toBeNull()
        ->and($issue->fixed_version_id)->toBeNull();
});

test('a default group applies only while group assignment is on and the group is assignable', function (bool $settingOn, bool $assignable, bool $applied) {
    Setting::set('issue_group_assignment', $settingOn);
    ['project' => $project, 'tracker' => $tracker] = creationDefaultsProject();
    $group = creationDefaultsGroup($project, $assignable);
    $project->update(['default_assigned_to_group_id' => $group->id]);

    $issue = creationDefaultsApiIssue($project, $tracker);

    expect($issue->assigned_to_group_id)->toBe($applied ? $group->id : null)
        ->and($issue->assigned_to_id)->toBeNull();
})->with([
    'on and assignable' => [true, true, true],
    'setting off' => [false, true, false],
    'not assignable' => [true, false, false],
]);

test('an issue imported from csv gets the category default assignee and the project default version', function () {
    ['project' => $project] = creationDefaultsProject();
    $projectDefault = creationDefaultsMember($project);
    $categoryDefault = creationDefaultsMember($project);
    $version = Version::factory()->for($project)->create();
    $project->update(['default_assigned_to_id' => $projectDefault->id, 'default_version_id' => $version->id]);
    IssueCategory::create(['project_id' => $project->id, 'name' => 'Backend', 'assigned_to_id' => $categoryDefault->id]);

    creationDefaultsImport($project, "subject,category\nWith category,Backend\nWithout category,\n", ['subject' => 'subject', 'category' => 'category']);

    $withCategory = Issue::query()->where('subject', 'With category')->sole();
    $withoutCategory = Issue::query()->where('subject', 'Without category')->sole();

    expect($withCategory->assigned_to_id)->toBe($categoryDefault->id)
        ->and($withCategory->fixed_version_id)->toBe($version->id)
        ->and($withoutCategory->assigned_to_id)->toBe($projectDefault->id);
});

test('an import skips a category default who can no longer be assigned and falls back to the project default', function () {
    ['project' => $project] = creationDefaultsProject();
    $projectDefault = creationDefaultsMember($project);
    IssueCategory::create(['project_id' => $project->id, 'name' => 'Backend', 'assigned_to_id' => creationDefaultsMember($project, assignable: false)->id]);
    $project->update(['default_assigned_to_id' => $projectDefault->id]);

    creationDefaultsImport($project, "subject,category\nStale category default,Backend\n", ['subject' => 'subject', 'category' => 'category']);

    expect(Issue::query()->where('subject', 'Stale category default')->sole()->assigned_to_id)->toBe($projectDefault->id);
});

test('an imported row keeps the assignee and version it names', function () {
    ['project' => $project] = creationDefaultsProject();
    $default = creationDefaultsMember($project);
    $chosen = creationDefaultsMember($project, attributes: ['email' => 'chosen@example.com']);
    $defaultVersion = Version::factory()->for($project)->create();
    Version::factory()->for($project)->create(['name' => 'v2']);
    $project->update(['default_assigned_to_id' => $default->id, 'default_version_id' => $defaultVersion->id]);

    creationDefaultsImport($project, "subject,assignee,version\nNamed,chosen@example.com,v2\n", ['subject' => 'subject', 'assigned_to' => 'assignee', 'fixed_version' => 'version']);

    $issue = Issue::query()->where('subject', 'Named')->sole();

    expect($issue->assigned_to_id)->toBe($chosen->id)
        ->and($issue->fixedVersion->name)->toBe('v2');
});

test('an issue created from incoming mail gets the project defaults', function () {
    ['project' => $project, 'tracker' => $tracker, 'status' => $status] = creationDefaultsProject();
    $assignee = creationDefaultsMember($project);
    $version = Version::factory()->for($project)->create();
    $project->update(['default_assigned_to_id' => $assignee->id, 'default_version_id' => $version->id]);

    $issue = creationDefaultsMailIssue($project, $tracker, $status);

    expect($issue->assigned_to_id)->toBe($assignee->id)
        ->and($issue->fixed_version_id)->toBe($version->id);
});

test('an incoming mail category keyword brings the category default assignee', function () {
    ['project' => $project, 'tracker' => $tracker, 'status' => $status] = creationDefaultsProject();
    $projectDefault = creationDefaultsMember($project);
    $categoryDefault = creationDefaultsMember($project);
    $project->update(['default_assigned_to_id' => $projectDefault->id]);
    IssueCategory::create(['project_id' => $project->id, 'name' => 'Backend', 'assigned_to_id' => $categoryDefault->id]);

    $issue = creationDefaultsMailIssue($project, $tracker, $status, "Please fix it.\n\nCategory: Backend");

    expect($issue->category?->name)->toBe('Backend')
        ->and($issue->assigned_to_id)->toBe($categoryDefault->id);
});

test('a bulk copy does not pick up the project default version', function () {
    ['project' => $project, 'tracker' => $tracker, 'status' => $status] = creationDefaultsProject();
    $version = Version::factory()->for($project)->create();
    $project->update(['default_version_id' => $version->id]);
    $source = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => $status->id, 'fixed_version_id' => null]);

    $copy = app(IssueService::class)->copy($source, $project, $tracker->id, creationDefaultsMember($project, ['view_issues', 'add_issues']));

    expect($copy->fixed_version_id)->toBeNull();
});

test('the new issue form re-applies the default assignee when it is cleared, but keeps a cleared version empty', function () {
    ['project' => $project, 'tracker' => $tracker] = creationDefaultsProject();
    $assignee = creationDefaultsMember($project);
    $version = Version::factory()->for($project)->create();
    $project->update(['default_assigned_to_id' => $assignee->id, 'default_version_id' => $version->id]);

    Livewire::actingAs(creationDefaultsMember($project, ['view_issues', 'add_issues']))
        ->test('issues.form', ['project' => $project->fresh()])
        ->set('tracker_id', $tracker->id)
        ->set('subject', 'Cleared in the form')
        ->set('assigneeChoice', '')
        ->set('fixed_version_id', null)
        ->call('save')
        ->assertHasNoErrors();

    $issue = Issue::query()->where('subject', 'Cleared in the form')->sole();

    expect($issue->assigned_to_id)->toBe($assignee->id)
        ->and($issue->fixed_version_id)->toBeNull();
});
