<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Pagination\PageSize;
use Livewire\Livewire;

function pageSizeMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

function pageSizeIssues(Project $project, int $count, string $subject = 'Sized issue'): void
{
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    Issue::factory($count)->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => $subject,
    ]);
}

test('per_page_options parses commas and whitespace into ascending positive integers', function () {
    expect(PageSize::parse('100, 25  50,,x,0,-3,25'))->toBe([25, 50, 100])
        ->and(PageSize::parse(''))->toBe([]);

    Setting::set('per_page_options', '10 20');
    expect(PageSize::options())->toBe([10, 20]);
});

test('the selector offers only sizes that make sense for the item count', function () {
    Setting::set('per_page_options', '25,50,100');

    expect(PageSize::selectableFor(25, 20))->toBe([])
        ->and(PageSize::selectableFor(25, 30))->toBe([25, 50])
        ->and(PageSize::selectableFor(25, 500))->toBe([25, 50, 100])
        ->and(PageSize::selectableFor(7, 500))->toBe([25, 50, 100])
        ->and(PageSize::resolve(50, 25))->toBe(50)
        ->and(PageSize::resolve(33, 25))->toBe(25)
        ->and(PageSize::resolve(null, 25))->toBe(25);
});

test('the issue list follows a chosen page size and ignores one that is not an option', function () {
    Setting::set('default_issues_per_page', 5);
    Setting::set('per_page_options', '5,10,20');
    $project = Project::factory()->create();
    $user = pageSizeMember($project, ['view_project', 'search_project', 'view_issues']);
    pageSizeIssues($project, 12);

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project]);
    expect($list->get('issues')->perPage())->toBe(5);

    $list->set('perPage', 10);
    expect($list->get('issues')->perPage())->toBe(10)
        ->and($list->get('issues')->count())->toBe(10);
    $list->assertSee('表示件数');

    $list->set('perPage', 9999);
    expect($list->get('issues')->perPage())->toBe(5);
});

test('changing the page size returns to the first page', function () {
    Setting::set('per_page_options', '5,10');
    $project = Project::factory()->create();
    $user = pageSizeMember($project, ['view_project', 'search_project', 'view_issues']);
    pageSizeIssues($project, 12);

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('perPage', 5)
        ->call('gotoPage', 3)
        ->set('perPage', 10);

    expect($list->get('issues')->currentPage())->toBe(1);
});

test('the selector is hidden when every row already fits the smallest option', function () {
    Setting::set('per_page_options', '25,50,100');
    $project = Project::factory()->create();
    $user = pageSizeMember($project, ['view_project', 'search_project', 'view_issues']);
    pageSizeIssues($project, 3);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->assertDontSee('表示件数');
});

test('the global issue list and the global news list honour the page size too', function () {
    Setting::set('per_page_options', '5,10');
    $project = Project::factory()->create();
    $user = pageSizeMember($project, ['view_project', 'search_project', 'view_issues', 'view_news']);
    pageSizeIssues($project, 12);
    News::factory(12)->for($project)->create();

    $issues = Livewire::actingAs($user)->test('issues.global-index')->set('perPage', 5);
    expect($issues->get('issues')->perPage())->toBe(5);

    $news = Livewire::actingAs($user)->test('news.global-index')->set('perPage', 5);
    expect($news->get('newsItems')->perPage())->toBe(5)
        ->and(Livewire::actingAs($user)->test('news.global-index')->get('newsItems')->perPage())->toBe(10);
});

test('search results are paginated at search_results_per_page', function () {
    Setting::set('search_results_per_page', 4);
    $project = Project::factory()->create();
    $user = pageSizeMember($project, ['view_project', 'search_project', 'view_issues']);
    pageSizeIssues($project, 9, 'paged-needle');

    $search = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'paged-needle')
        ->call('search');

    expect($search->get('pagedResults')->count())->toBe(4)
        ->and($search->get('pagedResults')->total())->toBe(9);

    $search->call('gotoPage', 3);
    expect($search->get('pagedResults')->count())->toBe(1);

    $search->set('query', 'paged-needle ');
    expect($search->get('pagedResults')->currentPage())->toBe(1);
});

test('the global search paginates and shows only what the user may see', function () {
    Setting::set('search_results_per_page', 3);
    $visible = Project::factory()->create();
    $hidden = Project::factory()->create();
    $user = pageSizeMember($visible, ['view_project', 'search_project', 'view_issues']);
    pageSizeIssues($visible, 5, 'global-paged-needle');
    pageSizeIssues($hidden, 5, 'global-paged-needle');

    $search = Livewire::actingAs($user)
        ->test('search.global-index')
        ->set('query', 'global-paged-needle')
        ->call('search');

    expect($search->get('pagedResults')->total())->toBe(5)
        ->and($search->get('pagedResults')->count())->toBe(3);
});

test('an unset search_results_per_page falls back to 10', function () {
    expect(PageSize::searchResults())->toBe(10);

    Setting::set('search_results_per_page', 0);
    expect(PageSize::searchResults())->toBe(10);
});

test('the settings form saves the page size options and rejects malformed ones', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->set('per_page_options', '20, 40 80')
        ->set('search_results_per_page', 15)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('per_page_options'))->toBe('20, 40 80')
        ->and(Setting::get('search_results_per_page'))->toBe(15)
        ->and(PageSize::options())->toBe([20, 40, 80]);

    Livewire::actingAs($admin)->test('settings.index')
        ->set('per_page_options', 'abc')
        ->call('save')
        ->assertHasErrors(['per_page_options']);

    Livewire::actingAs($admin)->test('settings.index')
        ->set('per_page_options', '0,10')
        ->set('search_results_per_page', 0)
        ->call('save')
        ->assertHasErrors(['per_page_options', 'search_results_per_page']);
});
