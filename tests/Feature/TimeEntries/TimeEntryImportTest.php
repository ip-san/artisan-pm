<?php

use App\Enums\ImportStatus;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\TimeEntryImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function timeEntryImportMember(Project $project, array $permissions = ['view_time_entries', 'log_time']): User
{
    $role = Role::factory()->create(['permissions' => $permissions]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function timeEntryCsvFile(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

test('uploading a csv auto-detects headers and lets them be mapped', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $user = timeEntryImportMember($project);

    $csv = "spent_on,hours\n2026-01-01,2.5\n";

    $component = Livewire::actingAs($user)
        ->test('time-entries.import', ['project' => $project])
        ->set('csvFile', timeEntryCsvFile('time_entries.csv', $csv));

    expect($component->get('headers'))->toBe(['spent_on', 'hours'])
        ->and($component->get('mapping')['spent_on'])->toBe('spent_on')
        ->and($component->get('mapping')['hours'])->toBe('hours');
});

test('starting an import dispatches a job that creates time entries from csv rows', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    Enumeration::factory()->create(['type' => 'time_entry_activity', 'is_default' => true]);
    $user = timeEntryImportMember($project);

    $csv = "日付,時間,コメント\n2026-01-01,2.5,First entry\n2026-01-02,1,Second entry\n";

    Livewire::actingAs($user)
        ->test('time-entries.import', ['project' => $project])
        ->set('csvFile', timeEntryCsvFile('time_entries.csv', $csv))
        ->set('mapping.spent_on', '日付')
        ->set('mapping.hours', '時間')
        ->set('mapping.comments', 'コメント')
        ->call('startImport')
        ->assertRedirect();

    $import = TimeEntryImport::firstOrFail();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->imported_count)->toBe(2)
        ->and($import->failed_count)->toBe(0)
        ->and(TimeEntry::where('comments', 'First entry')->where('hours', 2.5)->exists())->toBeTrue()
        ->and(TimeEntry::where('comments', 'Second entry')->where('hours', 1)->exists())->toBeTrue();

    expect(TimeEntry::where('comments', 'First entry')->first()->user_id)->toBe($user->id);
});

test('a row missing hours or spent_on is recorded as a failure without stopping the rest of the import', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    Enumeration::factory()->create(['type' => 'time_entry_activity', 'is_default' => true]);
    $user = timeEntryImportMember($project);

    $csv = "spent_on,hours\n,2\n2026-01-01,\n2026-01-02,3\n";

    Livewire::actingAs($user)
        ->test('time-entries.import', ['project' => $project])
        ->set('csvFile', timeEntryCsvFile('time_entries.csv', $csv))
        ->set('mapping.spent_on', 'spent_on')
        ->set('mapping.hours', 'hours')
        ->call('startImport');

    $import = TimeEntryImport::firstOrFail();

    expect($import->imported_count)->toBe(1)
        ->and($import->failed_count)->toBe(2)
        ->and($import->errors)->toHaveCount(2);
});

test('an activity resolves by name and falls back to the project default', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    Enumeration::factory()->create(['type' => 'time_entry_activity', 'name' => 'Default Activity', 'is_default' => true]);
    $namedActivity = Enumeration::factory()->create(['type' => 'time_entry_activity', 'name' => 'Design']);
    $user = timeEntryImportMember($project);

    $csv = "spent_on,hours,activity\n2026-01-01,1,\n2026-01-02,2,Design\n";

    Livewire::actingAs($user)
        ->test('time-entries.import', ['project' => $project])
        ->set('csvFile', timeEntryCsvFile('time_entries.csv', $csv))
        ->set('mapping.spent_on', 'spent_on')
        ->set('mapping.hours', 'hours')
        ->set('mapping.activity', 'activity')
        ->call('startImport');

    $usesDefault = TimeEntry::where('hours', 1)->firstOrFail();
    $usesNamed = TimeEntry::where('hours', 2)->firstOrFail();

    expect($usesNamed->activity_id)->toBe($namedActivity->id)
        ->and($usesDefault->activity_id)->not->toBe($namedActivity->id);
});

test('a mapped issue column links the time entry to that issue', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    Enumeration::factory()->create(['type' => 'time_entry_activity', 'is_default' => true]);
    $user = timeEntryImportMember($project);

    $csv = "spent_on,hours,issue\n2026-01-01,1,#{$issue->id}\n";

    Livewire::actingAs($user)
        ->test('time-entries.import', ['project' => $project])
        ->set('csvFile', timeEntryCsvFile('time_entries.csv', $csv))
        ->set('mapping.spent_on', 'spent_on')
        ->set('mapping.hours', 'hours')
        ->set('mapping.issue', 'issue')
        ->call('startImport');

    $entry = TimeEntry::where('hours', 1)->firstOrFail();
    expect($entry->issue_id)->toBe($issue->id);
});

test('a "user" column is ignored, entries logged as the importer, when the importer lacks edit_time_entries', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    Enumeration::factory()->create(['type' => 'time_entry_activity', 'is_default' => true]);
    $importer = timeEntryImportMember($project, ['view_time_entries', 'log_time']);
    $otherMember = timeEntryImportMember($project, ['view_time_entries', 'log_time']);

    $csv = "spent_on,hours,user\n2026-01-01,1,{$otherMember->email}\n";

    Livewire::actingAs($importer)
        ->test('time-entries.import', ['project' => $project])
        ->set('csvFile', timeEntryCsvFile('time_entries.csv', $csv))
        ->set('mapping.spent_on', 'spent_on')
        ->set('mapping.hours', 'hours')
        ->set('mapping.user', 'user')
        ->call('startImport');

    $entry = TimeEntry::where('hours', 1)->firstOrFail();
    expect($entry->user_id)->toBe($importer->id)->not->toBe($otherMember->id);
});

test('a "user" column attributes the entry to that member when the importer holds edit_time_entries', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    Enumeration::factory()->create(['type' => 'time_entry_activity', 'is_default' => true]);
    $importer = timeEntryImportMember($project, ['view_time_entries', 'log_time', 'edit_time_entries']);
    $otherMember = timeEntryImportMember($project, ['view_time_entries', 'log_time']);

    $csv = "spent_on,hours,user\n2026-01-01,1,{$otherMember->email}\n";

    Livewire::actingAs($importer)
        ->test('time-entries.import', ['project' => $project])
        ->set('csvFile', timeEntryCsvFile('time_entries.csv', $csv))
        ->set('mapping.spent_on', 'spent_on')
        ->set('mapping.hours', 'hours')
        ->set('mapping.user', 'user')
        ->call('startImport');

    $entry = TimeEntry::where('hours', 1)->firstOrFail();
    expect($entry->user_id)->toBe($otherMember->id);
});

test('a "user" email matching a user outside the project falls back to the importer', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    Enumeration::factory()->create(['type' => 'time_entry_activity', 'is_default' => true]);
    $importer = timeEntryImportMember($project, ['view_time_entries', 'log_time', 'edit_time_entries']);
    $outsider = User::factory()->create(['email' => 'outsider@example.com']);

    $csv = "spent_on,hours,user\n2026-01-01,1,outsider@example.com\n";

    Livewire::actingAs($importer)
        ->test('time-entries.import', ['project' => $project])
        ->set('csvFile', timeEntryCsvFile('time_entries.csv', $csv))
        ->set('mapping.spent_on', 'spent_on')
        ->set('mapping.hours', 'hours')
        ->set('mapping.user', 'user')
        ->call('startImport');

    $entry = TimeEntry::where('hours', 1)->firstOrFail();
    expect($entry->user_id)->toBe($importer->id)
        ->and($entry->user_id)->not->toBe($outsider->id);
});

test('a user without log_time cannot start an import', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_time_entries']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    Livewire::actingAs($user)->test('time-entries.import', ['project' => $project])->assertForbidden();
});

test('the import status page shows progress and errors once finished', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    Enumeration::factory()->create(['type' => 'time_entry_activity', 'is_default' => true]);
    $user = timeEntryImportMember($project);

    $csv = "spent_on,hours\n2026-01-01,1\n";

    Livewire::actingAs($user)
        ->test('time-entries.import', ['project' => $project])
        ->set('csvFile', timeEntryCsvFile('time_entries.csv', $csv))
        ->set('mapping.spent_on', 'spent_on')
        ->set('mapping.hours', 'hours')
        ->call('startImport');

    $import = TimeEntryImport::firstOrFail();

    Livewire::actingAs($user)
        ->test('time-entries.import-status', ['project' => $project, 'import' => $import])
        ->assertSee('完了しました')
        ->assertSee('1');
});
