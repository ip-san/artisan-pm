<?php

use App\Enums\VersionStatus;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Version;
use App\Services\WikiPageService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

// ISO dates, so the expectations read as the stored values (an empty setting follows the language).
beforeEach(function () {
    Setting::set('date_format', '%Y-%m-%d');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @param  array<int, string>  $permissions
 */
function zonedProjectMember(Project $project, string $zone, array $permissions): User
{
    $user = User::factory()->create(['time_zone' => $zone]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('news times are shown in the viewer zone', function () {
    $project = Project::factory()->create();
    $news = News::factory()->for($project)->create();
    $news->forceFill(['created_at' => '2026-09-24 23:30:00'])->saveQuietly();

    $this->actingAs(zonedProjectMember($project, 'Asia/Tokyo', ['view_project', 'view_news']))
        ->get(route('news.show', [$project, $news]))
        ->assertOk()
        ->assertSee('2026-09-25 08:30')
        ->assertDontSee('2026-09-24 23:30');
});

test('the wiki date index groups pages by the viewer day', function () {
    $project = Project::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Late Page'], 'text', User::factory()->create());
    $page->currentVersion->forceFill(['created_at' => '2026-09-24 23:30:00'])->save();

    $tokyo = Livewire::actingAs(zonedProjectMember($project, 'Asia/Tokyo', ['view_wiki_pages']))
        ->test('wiki.date-index', ['project' => $project]);
    expect($tokyo->get('pagesByDate')->keys()->all())->toBe(['2026-09-25']);

    $utc = Livewire::actingAs(zonedProjectMember($project, 'UTC', ['view_wiki_pages']))
        ->test('wiki.date-index', ['project' => $project]);
    expect($utc->get('pagesByDate')->keys()->all())->toBe(['2026-09-24']);
});

test('a version is late only after its due day in the viewer zone', function () {
    // 2026-09-25 08:30 in Tokyo, still 2026-09-24 in Los Angeles.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-24 23:30:00', 'UTC'));
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create(['status' => VersionStatus::Open->value, 'due_date' => '2026-09-24']);

    $this->actingAs(zonedProjectMember($project, 'America/Los_Angeles', ['view_project', 'view_issues']));
    expect($version->isCompleted())->toBeFalse();
    $this->get(route('versions.roadmap', $project))->assertOk()->assertSee(__('(あと:days日)', ['days' => 0]));

    $this->actingAs(zonedProjectMember($project, 'Asia/Tokyo', ['view_project', 'view_issues']));
    expect($version->isCompleted())->toBeTrue();
});
