<?php

use App\Enums\EnumerationType;
use App\Enums\PermissionRequirement;
use App\Models\Enumeration;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeEntryService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function permissionMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

function permissionActivity(): Enumeration
{
    return Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
}

test('editing your own entry needs edit_own_time_entries', function () {
    $project = Project::factory()->create();
    $without = permissionMember($project, ['view_project', 'view_time_entries', 'log_time']);
    $with = permissionMember($project, ['view_project', 'view_time_entries', 'log_time', 'edit_own_time_entries']);
    $mine = TimeEntry::factory()->for($project)->for($without)->create();
    $theirs = TimeEntry::factory()->for($project)->for($with)->create();

    expect($without->can('update', $mine))->toBeFalse()
        ->and($with->can('update', $theirs))->toBeTrue()
        ->and($with->can('update', $mine))->toBeFalse();
});

test('edit_time_entries still lets a member edit anyone entry', function () {
    $project = Project::factory()->create();
    $editor = permissionMember($project, ['view_project', 'view_time_entries', 'edit_time_entries']);
    $entry = TimeEntry::factory()->for($project)->create();

    expect($editor->can('update', $entry))->toBeTrue()
        ->and($editor->can('delete', $entry))->toBeTrue();
});

test('the list shows edit controls only on entries the viewer may change', function () {
    $project = Project::factory()->create();
    $user = permissionMember($project, ['view_project', 'view_time_entries', 'log_time', 'edit_own_time_entries']);
    $mine = TimeEntry::factory()->for($project)->for($user)->create(['comments' => 'my-entry']);
    $other = TimeEntry::factory()->for($project)->create(['comments' => 'their-entry']);

    $html = Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])->html();

    expect($html)->toContain(route('time-entries.edit', [$project, $mine]))
        ->not->toContain(route('time-entries.edit', [$project, $other]));
});

test('a new entry records who logged it, separately from whose time it is', function () {
    $project = Project::factory()->create();
    $manager = permissionMember($project, ['view_project', 'log_time', 'log_time_for_other_users']);
    $colleague = permissionMember($project, ['view_project', 'log_time']);
    $activity = permissionActivity();

    Livewire::actingAs($manager)->test('time-entries.form', ['project' => $project])
        ->set('user_id', $colleague->id)
        ->set('activity_id', $activity->id)
        ->set('hours', '2')
        ->set('spent_on', now()->toDateString())
        ->call('save');

    $entry = TimeEntry::query()->firstOrFail();
    expect($entry->user_id)->toBe($colleague->id)
        ->and($entry->author_id)->toBe($manager->id)
        ->and($entry->author->is($manager))->toBeTrue();
});

test('the API also records the author and gates user_id on log_time_for_other_users', function () {
    $project = Project::factory()->create();
    $manager = permissionMember($project, ['view_project', 'log_time', 'log_time_for_other_users']);
    $plain = permissionMember($project, ['view_project', 'log_time', 'edit_time_entries']);
    $colleague = permissionMember($project, ['view_project']);
    $activity = permissionActivity();

    Passport::actingAs($manager);
    $this->postJson("/api/v1/projects/{$project->id}/time_entries", ['user_id' => $colleague->id, 'activity_id' => $activity->id, 'hours' => 1])->assertCreated();

    Passport::actingAs($plain);
    $this->postJson("/api/v1/projects/{$project->id}/time_entries", ['user_id' => $colleague->id, 'activity_id' => $activity->id, 'hours' => 1])->assertCreated();

    $byManager = TimeEntry::query()->where('author_id', $manager->id)->firstOrFail();
    $byPlain = TimeEntry::query()->where('author_id', $plain->id)->firstOrFail();
    expect($byManager->user_id)->toBe($colleague->id)
        ->and($byPlain->user_id)->toBe($plain->id);
});

test('the service falls back to the entry user when nobody is signed in', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $entry = app(TimeEntryService::class)->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'activity_id' => permissionActivity()->id,
        'hours' => 1,
        'spent_on' => now()->toDateString(),
    ]);

    expect($entry->author_id)->toBe($user->id);
});

test('the CSV importer is recorded as the author of every imported row', function () {
    Storage::fake('local');
    $project = Project::factory()->create();
    Enumeration::factory()->create(['type' => 'time_entry_activity', 'is_default' => true]);
    $importer = permissionMember($project, ['view_project', 'view_time_entries', 'log_time', 'import_time_entries']);

    Livewire::actingAs($importer)->test('time-entries.import', ['project' => $project])
        ->set('csvFile', UploadedFile::fake()->createWithContent('t.csv', "date,hours\n2026-01-01,2\n"))
        ->set('mapping.spent_on', 'date')
        ->set('mapping.hours', 'hours')
        ->call('startImport');

    $entry = TimeEntry::query()->firstOrFail();
    expect($entry->author_id)->toBe($importer->id)
        ->and($entry->user_id)->toBe($importer->id);
});

test('importing needs its own permission on top of log_time', function () {
    $project = Project::factory()->create();
    $logger = permissionMember($project, ['view_project', 'view_time_entries', 'log_time']);
    $importer = permissionMember($project, ['view_project', 'view_time_entries', 'log_time', 'import_time_entries']);
    $importOnly = permissionMember($project, ['view_project', 'view_time_entries', 'import_time_entries']);

    Livewire::actingAs($logger)->test('time-entries.import', ['project' => $project])->assertForbidden();
    Livewire::actingAs($importOnly)->test('time-entries.import', ['project' => $project])->assertForbidden();
    Livewire::actingAs($importer)->test('time-entries.import', ['project' => $project])->assertOk();

    expect(Livewire::actingAs($logger)->test('time-entries.index', ['project' => $project])->html())->not->toContain('time_entries/import')
        ->and(Livewire::actingAs($importer)->test('time-entries.index', ['project' => $project])->html())->toContain('time_entries/import');
});

test('importing issues needs import_issues on top of add_issues', function () {
    $project = Project::factory()->create();
    $adder = permissionMember($project, ['view_project', 'view_issues', 'add_issues']);
    $importer = permissionMember($project, ['view_project', 'view_issues', 'add_issues', 'import_issues']);

    Livewire::actingAs($adder)->test('issues.import', ['project' => $project])->assertForbidden();
    Livewire::actingAs($importer)->test('issues.import', ['project' => $project])->assertOk();
});

test('the new permissions are registered with Redmine\'s grant requirements', function () {
    $registry = app(PermissionRegistry::class);

    expect($registry->get('edit_own_time_entries')->requirement)->toBe(PermissionRequirement::LoggedIn)
        ->and($registry->get('log_time_for_other_users')->requirement)->toBe(PermissionRequirement::Member)
        ->and($registry->has('import_time_entries'))->toBeTrue()
        ->and($registry->has('import_issues'))->toBeTrue();
});
