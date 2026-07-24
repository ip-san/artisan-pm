<?php

use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function csvExportMember(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('the default UTF-8 export starts with a byte-order mark', function () {
    $project = Project::factory()->create();
    $user = csvExportMember($project);
    Issue::factory()->for($project)->create(['subject' => 'Exportable issue']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject'])
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['題名']).csvRow(['Exportable issue'])
        );
});

test('a semicolon separator is honored', function () {
    $project = Project::factory()->create();
    $user = csvExportMember($project);
    $issue = Issue::factory()->for($project)->create(['subject' => 'Row']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['tracker_id', 'subject'])
        ->set('csvSeparator', ';')
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['トラッカー', '題名'], ';').csvRow([$issue->tracker->name, 'Row'], ';')
        );
});

test('the Shift_JIS export is transcoded and has no byte-order mark', function () {
    $project = Project::factory()->create();
    $user = csvExportMember($project);
    Issue::factory()->for($project)->create(['subject' => 'テスト']);

    $expected = mb_convert_encoding(csvRow(['題名']).csvRow(['テスト']), 'SJIS-win', 'UTF-8');

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject'])
        ->set('csvEncoding', 'SJIS-win')
        ->call('exportCsv')
        ->assertFileDownloaded("{$project->identifier}-issues.csv", $expected);
});

test('an invalid encoding value falls back to UTF-8', function () {
    $project = Project::factory()->create();
    $user = csvExportMember($project);
    Issue::factory()->for($project)->create(['subject' => 'Fallback']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject'])
        ->set('csvEncoding', 'not-a-real-encoding')
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['題名']).csvRow(['Fallback'])
        );
});

test('the relations column lists each relation with its label and the related issue id', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = csvExportMember($project);
    $issue = Issue::factory()->for($project)->create(['subject' => 'Main issue']);
    // In a separate project so they don't also appear as rows in this
    // export — only their ids in the relations column are being tested.
    $blocked = Issue::factory()->for($otherProject)->create();
    $blocker = Issue::factory()->for($otherProject)->create();
    IssueRelation::create(['issue_from_id' => $issue->id, 'issue_to_id' => $blocked->id, 'relation_type' => 'blocks']);
    IssueRelation::create(['issue_from_id' => $blocker->id, 'issue_to_id' => $issue->id, 'relation_type' => 'blocks']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject', 'relations'])
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['題名', '関連するチケット'])
                .csvRow(['Main issue', "ブロックする #{$blocked->id}, ブロックされている #{$blocker->id}"])
        );
});

test('the attachments column lists filenames one per line', function () {
    $project = Project::factory()->create();
    $user = csvExportMember($project);
    $issue = Issue::factory()->for($project)->create(['subject' => 'Has files']);
    $issue->addMediaFromString('a')->usingFileName('a.txt')->toMediaCollection('attachments');
    $issue->addMediaFromString('b')->usingFileName('b.txt')->toMediaCollection('attachments');

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject', 'attachments'])
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['題名', '添付ファイル']).csvRow(['Has files', "a.txt\nb.txt"])
        );
});

test('the watchers column lists watcher names one per line', function () {
    $project = Project::factory()->create();
    $user = csvExportMember($project);
    $issue = Issue::factory()->for($project)->create(['subject' => 'Watched issue']);
    $watcherA = User::factory()->create(['name' => 'Alice']);
    $watcherB = User::factory()->create(['name' => 'Bob']);
    $issue->watchers()->create(['user_id' => $watcherA->id]);
    $issue->watchers()->create(['user_id' => $watcherB->id]);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject', 'watchers'])
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['題名', 'ウォッチャー']).csvRow(['Watched issue', "Alice\nBob"])
        );
});

test('relations, attachments, and watchers columns are empty when there are none', function () {
    $project = Project::factory()->create();
    $user = csvExportMember($project);
    Issue::factory()->for($project)->create(['subject' => 'Plain issue']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject', 'relations', 'attachments', 'watchers'])
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['題名', '関連するチケット', '添付ファイル', 'ウォッチャー'])
                .csvRow(['Plain issue', '', '', ''])
        );
});
