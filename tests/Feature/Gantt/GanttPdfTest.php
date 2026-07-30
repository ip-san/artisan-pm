<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Livewire\Livewire;

function ganttPdfMember(Project $project, array $permissions = ['view_gantt']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

/**
 * @return array{tracker_id: int, status_id: int, priority_id: int, author_id: int}
 */
function ganttPdfIssueDefaults(): array
{
    return [
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => User::factory()->create()->id,
    ];
}

test('a member with view_gantt can download the chart as a PDF', function () {
    $project = Project::factory()->create();
    $user = ganttPdfMember($project);
    Issue::factory()->for($project)->create([
        ...ganttPdfIssueDefaults(),
        'subject' => '日本語の課題名',
        'start_date' => '2026-01-01',
        'due_date' => '2026-01-10',
    ]);

    $component = Livewire::actingAs($user)
        ->test('gantt.index', ['project' => $project])
        ->call('exportPdf')
        ->assertFileDownloaded("{$project->identifier}-gantt.pdf");

    $content = base64_decode($component->effects['download']['content']);

    expect(substr($content, 0, 4))->toBe('%PDF')
        ->and($content)->toContain('IPAGothic');
});

test('a member without view_gantt cannot even open the chart, let alone export it', function () {
    $project = Project::factory()->create();
    $user = ganttPdfMember($project, []);

    // mount() itself authorizes 'viewGantt' before exportPdf's own check
    // would ever run, same as GanttTest's own "forbidden" coverage — a
    // member without the permission never reaches a mounted component to
    // call exportPdf on in the first place.
    Livewire::actingAs($user)->test('gantt.index', ['project' => $project])->assertForbidden();
});

test('exporting a project with no dated issues 404s instead of producing an empty PDF', function () {
    $project = Project::factory()->create();
    $user = ganttPdfMember($project);

    Livewire::actingAs($user)
        ->test('gantt.index', ['project' => $project])
        ->call('exportPdf')
        ->assertNotFound();
});

test('a milestone version with a due date appears in the exported PDF', function () {
    $project = Project::factory()->create();
    $user = ganttPdfMember($project);
    Issue::factory()->for($project)->create([
        ...ganttPdfIssueDefaults(),
        'start_date' => '2026-01-01',
        'due_date' => '2026-01-10',
    ]);
    $withoutVersion = base64_decode(
        Livewire::actingAs($user)->test('gantt.index', ['project' => $project])
            ->call('exportPdf')->effects['download']['content']
    );

    Version::factory()->for($project)->create(['name' => 'v1.0', 'due_date' => '2026-01-20']);
    $withVersion = base64_decode(
        Livewire::actingAs($user)->test('gantt.index', ['project' => $project])
            ->call('exportPdf')->effects['download']['content']
    );

    // Both render successfully; the milestone marker's presence is what
    // should make the version's PDF larger, not an error either way.
    expect(strlen($withVersion))->toBeGreaterThan(strlen($withoutVersion));
});
