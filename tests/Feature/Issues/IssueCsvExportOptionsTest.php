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
    // The related issues must be visible to the user to be listed (A1-27b,
    // Redmine's load_visible_relations).
    Member::factory()->for($otherProject)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
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

test('the relations column leaves out relations to issues the user cannot see', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create(['is_public' => false]);
    $user = csvExportMember($project);
    $issue = Issue::factory()->for($project)->create(['subject' => 'Main issue']);
    $hidden = Issue::factory()->for($otherProject)->create();
    IssueRelation::create(['issue_from_id' => $issue->id, 'issue_to_id' => $hidden->id, 'relation_type' => 'blocks']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject', 'relations'])
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['題名', '関連するチケット']).csvRow(['Main issue', ''])
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

test('csvColumns=all exports every available inline column, ignoring the shown-column set', function () {
    $project = Project::factory()->create();
    $user = csvExportMember($project);
    Issue::factory()->for($project)->create(['subject' => 'All columns issue']);

    $response = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject'])
        ->set('csvColumns', 'all')
        ->call('exportCsv');

    $content = base64_decode(data_get($response->effects, 'download.content'));

    // 'subject' was the only shown column, but 'all' pulls in inline
    // columns like 'tracker_id' that were never added to the list.
    expect($content)->toContain('トラッカー')->toContain('題名');
});

test('csvColumns=all excludes the block columns unless their checkboxes are also on', function () {
    $project = Project::factory()->create();
    $user = csvExportMember($project);
    Issue::factory()->for($project)->create(['subject' => 'Row', 'description' => 'Secret description']);

    $response = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject'])
        ->set('csvColumns', 'all')
        ->call('exportCsv');

    $content = base64_decode(data_get($response->effects, 'download.content'));

    expect($content)->not->toContain('Secret description');
});

test('the description checkbox appends the description column regardless of the columns radio', function () {
    $project = Project::factory()->create();
    $user = csvExportMember($project);
    Issue::factory()->for($project)->create(['subject' => 'Row', 'description' => 'The full description']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject'])
        ->set('csvIncludeDescription', true)
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['題名', '説明']).csvRow(['Row', 'The full description'])
        );
});

test('the last-notes checkbox appends the most recent notes journal as its own column', function () {
    $project = Project::factory()->create();
    $user = csvExportMember($project);
    $issue = Issue::factory()->for($project)->create(['subject' => 'Row']);
    $issue->journals()->create(['user_id' => $user->id, 'notes' => 'Older note', 'created_at' => now()->subDay()]);
    $issue->journals()->create(['user_id' => $user->id, 'notes' => 'Latest note', 'created_at' => now()]);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject'])
        ->set('csvIncludeLastNotes', true)
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['題名', '最新のコメント']).csvRow(['Row', 'Latest note'])
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
