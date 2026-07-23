<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

function timeEntryCsvExportMember(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['log_time', 'view_time_entries']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('the default UTF-8 export starts with a byte-order mark', function () {
    $project = Project::factory()->create();
    $user = timeEntryCsvExportMember($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    TimeEntry::factory()->for($project)->for($user)->create([
        'activity_id' => $activity->id,
        'spent_on' => '2026-01-15',
        'hours' => 2,
    ]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('columns', ['spent_on'])
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-time_entries.csv",
            "\xEF\xBB\xBF".csvRow(['日付']).csvRow(['2026-01-15'])
        );
});

test('a semicolon separator is honored', function () {
    $project = Project::factory()->create();
    $user = timeEntryCsvExportMember($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'name' => 'Development']);
    TimeEntry::factory()->for($project)->for($user)->create([
        'activity_id' => $activity->id,
        'spent_on' => '2026-01-15',
        'hours' => 2,
    ]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('columns', ['spent_on', 'activity_id'])
        ->set('csvSeparator', ';')
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-time_entries.csv",
            "\xEF\xBB\xBF".csvRow(['日付', '作業分類'], ';').csvRow(['2026-01-15', 'Development'], ';')
        );
});

test('the Shift_JIS export is transcoded and has no byte-order mark', function () {
    $project = Project::factory()->create();
    $user = timeEntryCsvExportMember($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    TimeEntry::factory()->for($project)->for($user)->create([
        'activity_id' => $activity->id,
        'spent_on' => '2026-01-15',
        'hours' => 2,
        'comments' => 'テスト',
    ]);

    $expected = mb_convert_encoding(csvRow(['コメント']).csvRow(['テスト']), 'SJIS-win', 'UTF-8');

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('columns', ['comments'])
        ->set('csvEncoding', 'SJIS-win')
        ->call('exportCsv')
        ->assertFileDownloaded("{$project->identifier}-time_entries.csv", $expected);
});

test('an invalid encoding value falls back to UTF-8', function () {
    $project = Project::factory()->create();
    $user = timeEntryCsvExportMember($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    TimeEntry::factory()->for($project)->for($user)->create([
        'activity_id' => $activity->id,
        'spent_on' => '2026-01-15',
        'hours' => 2,
    ]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('columns', ['spent_on'])
        ->set('csvEncoding', 'not-a-real-encoding')
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-time_entries.csv",
            "\xEF\xBB\xBF".csvRow(['日付']).csvRow(['2026-01-15'])
        );
});

test('an invalid separator value falls back to a comma', function () {
    $project = Project::factory()->create();
    $user = timeEntryCsvExportMember($project);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    TimeEntry::factory()->for($project)->for($user)->create([
        'activity_id' => $activity->id,
        'spent_on' => '2026-01-15',
        'hours' => 2,
    ]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('columns', ['spent_on'])
        ->set('csvSeparator', '|')
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-time_entries.csv",
            "\xEF\xBB\xBF".csvRow(['日付']).csvRow(['2026-01-15'])
        );
});
