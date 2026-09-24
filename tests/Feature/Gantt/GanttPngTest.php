<?php

use App\Enums\IssueVisibility;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Support\Gantt\GanttChart;
use App\Support\Gantt\GanttImageRenderer;
use App\Support\Gantt\GanttLine;
use App\Support\Gantt\GanttRow;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function ganttPngMember(Project $project, array $permissions = ['view_gantt', 'view_issues'], ?User $user = null, ?Role $role = null): User
{
    $user ??= User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        $role ?? Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

function ganttPngIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'start_date' => '2026-01-01',
        'due_date' => '2026-01-31',
        ...$attributes,
    ]);
}

function ganttPngDecode($component): GdImage
{
    $image = imagecreatefromstring(base64_decode($component->effects['download']['content']));
    expect($image)->toBeInstanceOf(GdImage::class);

    return $image;
}

function ganttPngRow(int $id, string $subject): GanttRow
{
    return new GanttRow($id, null, $subject, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-10'), 50, 'バグ', '新規', false, 0);
}

/**
 * The label cell of the first row, as a string of 0/1 per pixel (1 = not white).
 */
function ganttPngLabelPixels(GdImage $image): string
{
    $pixels = '';

    for ($y = GanttImageRenderer::HEADER_HEIGHT + 1; $y < GanttImageRenderer::HEADER_HEIGHT + GanttImageRenderer::ROW_HEIGHT; $y++) {
        for ($x = 1; $x < 120; $x++) {
            $pixels .= imagecolorat($image, $x, $y) === 0xFFFFFF ? '0' : '1';
        }
    }

    return $pixels;
}

test('a member with view_gantt downloads the project chart as a PNG of the expected size', function () {
    $project = Project::factory()->create();
    ganttPngIssue($project, ['subject' => '日本語の課題']);
    ganttPngIssue($project);
    Version::factory()->for($project)->create(['due_date' => '2026-01-20']);

    $component = Livewire::actingAs(ganttPngMember($project))
        ->test('gantt.index', ['project' => $project])
        ->call('exportPng')
        ->assertFileDownloaded("{$project->identifier}-gantt.png");

    $image = ganttPngDecode($component);

    // The default zoom (2) adds the week-number header.
    expect(imagesx($image))->toBe(GanttImageRenderer::width(31, 2))
        ->and(imagesy($image))->toBe(GanttImageRenderer::height(3, 2))
        ->and(GanttImageRenderer::height(3, 2))->toBe(GanttImageRenderer::height(3) + GanttImageRenderer::HEADER_HEIGHT);
});

test('zoom 1 exports the months header only, as Redmine does', function () {
    $project = Project::factory()->create();
    ganttPngIssue($project);

    $image = ganttPngDecode(Livewire::actingAs(ganttPngMember($project))->test('gantt.index', ['project' => $project])->set('zoom', 1)->call('exportPng'));

    expect(imagesy($image))->toBe(GanttImageRenderer::height(1));
});

test('the late part of an overdue bar is drawn red in the PNG', function () {
    $project = Project::factory()->create();
    ganttPngIssue($project, ['start_date' => now()->subDays(20)->toDateString(), 'due_date' => now()->subDays(2)->toDateString(), 'done_ratio' => 0]);

    $image = ganttPngDecode(Livewire::actingAs(ganttPngMember($project))->test('gantt.index', ['project' => $project])->call('exportPng'));
    $middle = GanttImageRenderer::headersHeight(2) + intdiv(GanttImageRenderer::ROW_HEIGHT, 2);
    $late = 0;

    for ($x = GanttImageRenderer::SUBJECT_WIDTH; $x < imagesx($image); $x++) {
        $late += imagecolorat($image, $x, $middle) === 0xFF6666 ? 1 : 0;
    }

    expect($late)->toBeGreaterThan(10);
});

test('exporting a PNG of a chart with no dated issues 404s', function () {
    $project = Project::factory()->create();

    Livewire::actingAs(ganttPngMember($project))
        ->test('gantt.index', ['project' => $project])
        ->call('exportPng')
        ->assertNotFound();
});

test('Japanese labels are drawn with glyphs, not blank or identical boxes', function () {
    $renderer = new GanttImageRenderer;
    $draw = function (string $subject) use ($renderer): GdImage {
        $row = ganttPngRow(1, $subject);
        $chart = new GanttChart(collect([$row]), collect(), 0);

        return imagecreatefromstring($renderer->render($chart, [GanttLine::issue($row, 0)]));
    };

    $sun = ganttPngLabelPixels($draw('日'));
    $book = ganttPngLabelPixels($draw('本'));

    expect(substr_count($sun, '1'))->toBeGreaterThan(20)
        ->and($sun)->not->toBe($book);
});

test('the cross-project PNG only receives rows the viewer may see', function () {
    $visibleProject = Project::factory()->create();
    $ownProject = Project::factory()->create();
    $noGanttProject = Project::factory()->create();
    $user = ganttPngMember($visibleProject, role: Role::factory()->create([
        'permissions' => ['view_gantt', 'view_issues'],
        'issues_visibility' => IssueVisibility::Default->value,
    ]));
    ganttPngMember($ownProject, user: $user, role: Role::factory()->create([
        'permissions' => ['view_gantt', 'view_issues'],
        'issues_visibility' => IssueVisibility::Own->value,
    ]));
    ganttPngMember($noGanttProject, ['view_issues'], $user);
    $other = User::factory()->create();

    $shown = ganttPngIssue($visibleProject);
    $mine = ganttPngIssue($ownProject, ['author_id' => $user->id]);
    $othersPrivate = ganttPngIssue($visibleProject, ['is_private' => true, 'author_id' => $other->id]);
    $othersInOwn = ganttPngIssue($ownProject, ['author_id' => $other->id]);
    $noGantt = ganttPngIssue($noGanttProject);

    $received = null;
    $this->mock(GanttImageRenderer::class, function ($mock) use (&$received): void {
        $mock->shouldReceive('render')->once()
            ->withArgs(function (GanttChart $chart, array $lines) use (&$received): bool {
                $received = $lines;

                return true;
            })
            ->andReturn('png');
    });

    Livewire::actingAs($user)->test('gantt.global-index')->call('exportPng')->assertFileDownloaded('gantt.png');

    $issueIds = collect($received)->where('kind', GanttLine::ISSUE)->map(fn (GanttLine $line) => $line->row->id)->sort()->values()->all();
    $projectIds = collect($received)->where('kind', GanttLine::PROJECT)->count();

    expect($issueIds)->toBe(collect([$shown->id, $mine->id])->sort()->values()->all())
        ->not->toContain($othersPrivate->id)
        ->not->toContain($othersInOwn->id)
        ->not->toContain($noGantt->id)
        ->and($projectIds)->toBe(2);
});

test('the cross-project PNG decodes with one row per visible line', function () {
    $project = Project::factory()->create();
    $user = ganttPngMember($project);
    ganttPngIssue($project, ['subject' => '横断の課題']);
    ganttPngIssue(Project::factory()->create(), ['subject' => 'Hidden']);

    $image = ganttPngDecode(Livewire::actingAs($user)->test('gantt.global-index')->call('exportPng'));

    expect(imagesy($image))->toBe(GanttImageRenderer::height(2, 2));
});

test('the cross-project chart also exports a PDF', function () {
    $project = Project::factory()->create();
    $user = ganttPngMember($project);
    ganttPngIssue($project, ['subject' => '横断の課題']);

    $component = Livewire::actingAs($user)->test('gantt.global-index')->call('exportPdf')->assertFileDownloaded('gantt.pdf');

    expect(substr(base64_decode($component->effects['download']['content']), 0, 4))->toBe('%PDF');
});

test('a chart spanning today draws the today line', function () {
    $project = Project::factory()->create();
    ganttPngIssue($project, ['start_date' => now()->subDays(5)->toDateString(), 'due_date' => now()->addDays(5)->toDateString()]);

    $image = ganttPngDecode(Livewire::actingAs(ganttPngMember($project))->test('gantt.index', ['project' => $project])->call('exportPng'));
    $red = 0;

    for ($x = GanttImageRenderer::SUBJECT_WIDTH; $x < imagesx($image); $x++) {
        $red += imagecolorat($image, $x, imagesy($image) - 3) === 0xFF0000 ? 1 : 0;
    }

    expect($red)->toBe(1);
});

test('an image larger than the pixel cap is refused so it fits a 128MB memory_limit', function () {
    expect(GanttImageRenderer::fits(31, 3))->toBeTrue()
        // Two years of days and 400 rows would be about 26M pixels.
        ->and(GanttImageRenderer::fits(730, 400))->toBeFalse()
        ->and(GanttImageRenderer::width(730) * GanttImageRenderer::height(400))->toBeGreaterThan(GanttImageRenderer::MAX_PIXELS);
});

test('a chart over the size limit shows a message instead of a download', function () {
    $project = Project::factory()->create();
    ganttPngIssue($project);
    config(['gantt.png_max_pixels' => 1000]);

    Livewire::actingAs(ganttPngMember($project))
        ->test('gantt.index', ['project' => $project])
        ->call('exportPng')
        ->assertHasErrors('png')
        ->assertNoFileDownloaded()
        ->assertSee('ガントチャートが大きすぎるため画像にできません');

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('gantt.global-index')
        ->call('exportPng')
        ->assertHasErrors('png')
        ->assertNoFileDownloaded();
});
