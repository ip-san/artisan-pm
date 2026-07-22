<?php

use App\Models\Issue;
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
