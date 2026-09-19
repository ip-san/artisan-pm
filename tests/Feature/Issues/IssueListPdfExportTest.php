<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Export\ExportLimit;
use Livewire\Livewire;

function listPdfMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('the issue list can be exported as a PDF with the chosen columns', function () {
    $project = Project::factory()->create();
    $user = listPdfMember($project);
    Issue::factory()->for($project)->create(['subject' => '日本語の課題']);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject'])
        ->call('exportPdf')
        ->assertFileDownloaded("{$project->identifier}-issues.pdf");

    $content = base64_decode($component->effects['download']['content']);

    expect(substr($content, 0, 4))->toBe('%PDF')->and($content)->toContain('IPAGothic');
});

test('someone who cannot see the project cannot reach the list to export it', function () {
    $project = Project::factory()->private()->create();
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)->test('issues.index', ['project' => $project])->assertForbidden();
});

test('the PDF view lists headings, rows, long-text blocks and the truncation note', function () {
    $project = Project::factory()->create(['name' => 'Alpha']);

    $html = view('pdf.issues', [
        'project' => $project,
        'headings' => ['題名', 'ステータス'],
        'rows' => collect([['id' => 7, 'cells' => ['First', 'Open'], 'blocks' => ['説明' => 'Long text here']]]),
        'total' => 9,
    ])->render();

    expect($html)->toContain('Alpha - 課題')->toContain('題名')->toContain('First')->toContain('Long text here')->toContain('全9件のうち先頭');
});

test('the CSV export stops at issues_export_limit', function () {
    Setting::set('issues_export_limit', 2);
    $project = Project::factory()->create();
    $user = listPdfMember($project);
    Issue::factory()->for($project)->count(4)->create();

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject'])
        ->call('exportCsv')
        ->assertFileDownloaded("{$project->identifier}-issues.csv");

    $lines = array_filter(explode("\n", trim(base64_decode($component->effects['download']['content']))));

    expect($lines)->toHaveCount(3); // header + 2 issues
});

test('the list warns when there are more issues than the export limit', function () {
    Setting::set('issues_export_limit', 2);
    $project = Project::factory()->create();
    $user = listPdfMember($project);
    Issue::factory()->for($project)->count(3)->create();

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('statusFilter', 'all')->assertSee('data-export-limit-warning', false)->assertSee('先頭の2件まで');

    Setting::set('issues_export_limit', 10);
    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('statusFilter', 'all')->assertDontSee('data-export-limit-warning', false);
});

test('the export limit defaults to 500 and is bounded', function () {
    expect(ExportLimit::issues())->toBe(500);

    Setting::set('issues_export_limit', 999999);
    expect(ExportLimit::issues())->toBe(ExportLimit::MAXIMUM);

    Setting::set('issues_export_limit', 0);
    expect(ExportLimit::issues())->toBe(500);
});

test('the settings page saves the limit and rejects out-of-range values', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('issues_export_limit', 250)->call('save')->assertHasNoErrors();
    expect(ExportLimit::issues())->toBe(250);

    Livewire::actingAs($admin)->test('settings.index')->set('issues_export_limit', 0)->call('save')->assertHasErrors(['issues_export_limit']);
    Livewire::actingAs($admin)->test('settings.index')->set('issues_export_limit', ExportLimit::MAXIMUM + 1)->call('save')->assertHasErrors(['issues_export_limit']);
});
