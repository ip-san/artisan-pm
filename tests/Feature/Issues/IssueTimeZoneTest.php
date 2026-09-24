<?php

use App\Enums\FilterOperator;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

// ISO dates, so the expectations read as the stored values (an empty setting follows the language).
beforeEach(function () {
    Setting::set('date_format', '%Y-%m-%d');
});

afterEach(fn () => Carbon::setTestNow());

function zonedIssueMember(Project $project, string $zone, array $permissions = ['view_project', 'view_issues', 'add_issues', 'edit_issues', 'log_time']): User
{
    $user = User::factory()->create(['time_zone' => $zone]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('the issue page shows its history times in the viewer zone and dates as stored', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create(['start_date' => '2026-09-24', 'due_date' => '2026-09-30']);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $issue->author_id, 'notes' => 'late note'])
        ->forceFill(['created_at' => '2026-09-24 23:30:00', 'updated_at' => '2026-09-24 23:30:00'])->saveQuietly();

    $this->actingAs(zonedIssueMember($project, 'Asia/Tokyo'))
        ->get(route('issues.show', [$project, $issue]))
        ->assertOk()
        ->assertSee('2026-09-25 08:30')
        ->assertDontSee('2026-09-24 23:30')
        ->assertSee('2026-09-24')
        ->assertSee('2026-09-30');

    $this->actingAs(zonedIssueMember($project, 'America/Los_Angeles'))
        ->get(route('issues.show', [$project, $issue]))
        ->assertSee('2026-09-24 16:30');
});

test('the issue list and its CSV show timestamps in the viewer zone', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create(['subject' => 'Zoned']);
    Issue::query()->whereKey($issue->id)->update(['created_at' => '2026-09-24 20:00:00', 'updated_at' => '2026-09-24 23:30:00']);
    $user = zonedIssueMember($project, 'Asia/Tokyo');

    $component = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('statusFilter', 'all');

    expect($component->instance()->columnValue($issue->fresh(), 'updated_at'))->toBe('2026-09-25 08:30')
        ->and($component->instance()->columnValue($issue->fresh(), 'created_at'))->toBe('2026-09-25');

    $component->set('columns', ['subject', 'updated_at'])
        ->call('exportCsv')
        ->assertFileDownloaded("{$project->identifier}-issues.csv");
});

test('a new issue defaults its dates to the viewer today', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-24 23:30:00', 'UTC'));
    Setting::set('default_issue_start_date_to_creation_date', true);
    Setting::set('default_issue_due_date_offset', 7);
    $project = Project::factory()->create();

    $tokyo = Livewire::actingAs(zonedIssueMember($project, 'Asia/Tokyo'))->test('issues.form', ['project' => $project]);
    expect($tokyo->get('start_date'))->toBe('2026-09-25')
        ->and($tokyo->get('due_date'))->toBe('2026-10-02');

    $utc = Livewire::actingAs(zonedIssueMember($project, 'UTC'))->test('issues.form', ['project' => $project]);
    expect($utc->get('start_date'))->toBe('2026-09-24');
});

test('a date filter on a timestamp column covers the whole day in the viewer zone', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    // 2026-09-25 01:00 in Tokyo.
    Issue::query()->whereKey($issue->id)->update(['created_at' => '2026-09-24 16:00:00']);
    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project));
    $matches = fn (string $operator, array $values) => $engine->applyFilters(Issue::query(), ['created_at' => ['operator' => $operator, 'values' => $values]])->pluck('id')->all();

    // UTC viewer: the issue is on the 24th, and "<= 24th" keeps it (it was
    // compared with midnight before).
    expect($matches(FilterOperator::LessOrEqual->value, ['2026-09-24']))->toBe([$issue->id])
        ->and($matches(FilterOperator::Equals->value, ['2026-09-24']))->toBe([$issue->id])
        ->and($matches(FilterOperator::Equals->value, ['2026-09-25']))->toBe([]);

    $this->actingAs(User::factory()->create(['time_zone' => 'Asia/Tokyo']));
    expect($matches(FilterOperator::Equals->value, ['2026-09-25']))->toBe([$issue->id])
        ->and($matches(FilterOperator::Equals->value, ['2026-09-24']))->toBe([])
        ->and($matches(FilterOperator::GreaterOrEqual->value, ['2026-09-25']))->toBe([$issue->id])
        ->and($matches(FilterOperator::LessOrEqual->value, ['2026-09-24']))->toBe([])
        ->and($matches(FilterOperator::Between->value, ['2026-09-25', '2026-09-25']))->toBe([$issue->id]);
});

test('the REST "today" filter is the caller today', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-24 23:30:00', 'UTC'));
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    // 23:00 on the 24th in Tokyo, where it is already the 25th.
    Issue::query()->whereKey($issue->id)->update(['created_at' => '2026-09-24 14:00:00']);
    $tokyo = zonedIssueMember($project, 'Asia/Tokyo');

    $ids = function (User $user) use ($project): array {
        app('auth')->forgetGuards();

        return collect($this->withHeaders(['X-Redmine-API-Key' => $user->regenerateApiKey()])
            ->getJson("/api/v1/projects/{$project->id}/issues?created_on=t")->assertOk()->json('data'))->pluck('id')->all();
    };

    expect($ids($tokyo))->toBe([])
        ->and($ids(zonedIssueMember($project, 'UTC')))->toBe([$issue->id]);
});
