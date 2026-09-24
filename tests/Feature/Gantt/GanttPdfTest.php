<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Support\Gantt\GanttChart;
use Livewire\Livewire;

function ganttPdfMember(Project $project, array $permissions = ['view_gantt', 'view_issues']): User
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

test('precedes and blocks relations are drawn in the PDF as two thin segments each', function () {
    $project = Project::factory()->create();
    $user = ganttPdfMember($project);
    $first = Issue::factory()->for($project)->create([...ganttPdfIssueDefaults(), 'start_date' => '2026-01-01', 'due_date' => '2026-01-10']);
    $second = Issue::factory()->for($project)->create([...ganttPdfIssueDefaults(), 'start_date' => '2026-01-11', 'due_date' => '2026-01-20']);
    $third = Issue::factory()->for($project)->create([...ganttPdfIssueDefaults(), 'start_date' => '2026-01-05', 'due_date' => '2026-01-25']);
    IssueRelation::create(['issue_from_id' => $first->id, 'issue_to_id' => $second->id, 'relation_type' => 'precedes']);
    IssueRelation::create(['issue_from_id' => $second->id, 'issue_to_id' => $third->id, 'relation_type' => 'blocks']);
    IssueRelation::create(['issue_from_id' => $first->id, 'issue_to_id' => $third->id, 'relation_type' => 'relates']);

    $component = Livewire::actingAs($user)->test('gantt.index', ['project' => $project]);
    $html = $component->instance()->pdfHtml();

    expect(substr_count($html, 'class="relation-segment"'))->toBe(4)
        ->and(substr_count($html, 'background: #228be6'))->toBe(2)
        ->and(substr_count($html, 'background: #fa5252'))->toBe(2);

    $pdf = base64_decode($component->call('exportPdf')->effects['download']['content']);
    expect(substr($pdf, 0, 4))->toBe('%PDF');
});

test('an elbow reaches the target start whether it begins after or before the source ends', function () {
    $forward = GanttChart::relationSegments([['x1' => 20.0, 'y1' => 8.0, 'x2' => 50.0, 'y2' => 40.0, 'color' => '#228be6', 'type' => 'precedes']]);
    $backward = GanttChart::relationSegments([['x1' => 60.0, 'y1' => 8.0, 'x2' => 30.0, 'y2' => 40.0, 'color' => '#228be6', 'type' => 'precedes']]);

    expect($forward)->toBe([
        ['left' => '20%', 'width' => '30%', 'top' => '8px', 'height' => '1px', 'color' => '#228be6'],
        ['left' => '50%', 'width' => '1px', 'top' => '8px', 'height' => '33px', 'color' => '#228be6'],
    ])->and($backward)->toBe([
        ['left' => '30%', 'width' => '30%', 'top' => '40px', 'height' => '1px', 'color' => '#228be6'],
        ['left' => '60%', 'width' => '1px', 'top' => '8px', 'height' => '33px', 'color' => '#228be6'],
    ]);
});

test('no relation segments are drawn when the chart has no relations', function () {
    $project = Project::factory()->create();
    $user = ganttPdfMember($project);
    Issue::factory()->for($project)->create([...ganttPdfIssueDefaults(), 'start_date' => '2026-01-01', 'due_date' => '2026-01-10']);

    $html = Livewire::actingAs($user)->test('gantt.index', ['project' => $project])->instance()->pdfHtml();

    expect($html)->not->toContain('relation-segment"');
});
