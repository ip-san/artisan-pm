<?php

use App\Enums\CustomizableType;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activity\ProjectLastActivity;
use App\Support\Format\DateTimes;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * A2-10: the project list's "project" filter (mine/bookmarks), the last
 * activity date column, projects.csv, board cards and the display type
 * of a saved query.
 *
 * @param  array<int, string>  $permissions
 */
function leftoverMember(Project $project, array $permissions, ?User $user = null): User
{
    $user ??= User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

/**
 * @param  array<int, string>  $values
 * @return array<int, string>
 */
function leftoverFilteredNames(User $user, string $key, string $operator, array $values): array
{
    $projects = Livewire::actingAs($user)->test('projects.index')
        ->set('activeFilterKeys', [$key])
        ->set('filterOperators', [$key => $operator])
        ->set('filterValues', [$key => $values])
        ->get('projects');

    return collect($projects->items())->pluck('name')->sort()->values()->all();
}

test('the project filter takes << my projects >> and << my bookmarks >> like Redmine', function () {
    $mine = Project::factory()->private()->create(['name' => 'Mine']);
    $bookmarked = Project::factory()->create(['name' => 'Bookmarked']);
    Project::factory()->create(['name' => 'Other']);
    $user = leftoverMember($mine, ['view_project']);
    $user->bookmarkedProjects()->attach($bookmarked->id);

    expect(leftoverFilteredNames($user, 'id', '=', ['mine']))->toBe(['Mine'])
        ->and(leftoverFilteredNames($user, 'id', '=', ['bookmarks']))->toBe(['Bookmarked'])
        ->and(leftoverFilteredNames($user, 'id', '=', ['mine', 'bookmarks']))->toBe(['Bookmarked', 'Mine'])
        ->and(leftoverFilteredNames($user, 'id', '!', ['mine']))->toBe(['Bookmarked', 'Other'])
        ->and(leftoverFilteredNames($user, 'id', '=', [(string) $bookmarked->id]))->toBe(['Bookmarked']);

    $options = Livewire::actingAs($user)->test('projects.index')->get('engine')->field('id')->options();
    expect(array_slice($options, 0, 2, true))->toBe(['mine' => '<< マイプロジェクト >>', 'bookmarks' => '<< ブックマーク >>']);
});

test('<< my projects >> matches nothing for a user without memberships', function () {
    Project::factory()->create(['name' => 'Public one']);
    $user = User::factory()->create();

    expect(leftoverFilteredNames($user, 'id', '=', ['mine']))->toBe([])
        ->and(leftoverFilteredNames($user, 'id', '=', ['bookmarks']))->toBe([])
        ->and(leftoverFilteredNames($user, 'id', '!', ['mine']))->toBe(['Public one']);
});

test('the project filter never reaches a project the viewer cannot see', function () {
    $hidden = Project::factory()->private()->create(['name' => 'Hidden']);
    Project::factory()->create(['name' => 'Public one']);
    $user = User::factory()->create();

    expect(leftoverFilteredNames($user, 'id', '=', [(string) $hidden->id]))->toBe([]);

    $options = Livewire::actingAs($user)->test('projects.index')->get('engine')->field('id')->options();
    expect($options)->not->toHaveKey($hidden->id);
});

test('the parent filter takes << my projects >> too', function () {
    $parent = Project::factory()->create(['name' => 'Parent']);
    Project::factory()->create(['name' => 'Child', 'parent_id' => $parent->id]);
    Project::factory()->create(['name' => 'Orphan']);
    $user = leftoverMember($parent->fresh(), ['view_project']);

    expect(leftoverFilteredNames($user, 'parent_id', '=', ['mine']))->toBe(['Child']);
});

test('the REST project list resolves mine to nothing for a user without memberships', function () {
    Project::factory()->create(['name' => 'Public one']);
    Passport::actingAs(User::factory()->create());

    $names = collect($this->getJson('/api/v1/projects?f[]=id&op[id]==&v[id][]=mine')->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toBe([]);
});

test('the last activity date column shows the newest event the viewer may see', function () {
    $project = Project::factory()->create(['name' => 'Busy']);
    Issue::factory()->for($project)->create(['created_at' => '2026-03-05 10:00:00']);
    $reader = leftoverMember($project, ['view_project', 'view_issues']);
    $outsider = leftoverMember($project, ['view_project']);

    $withIssues = Livewire::actingAs($reader)->test('projects.index')
        ->set('displayType', 'list')
        ->set('columns', ['name', 'last_activity_date']);

    expect($withIssues->instance()->columnValue($project, 'last_activity_date'))->toBe(DateTimes::date('2026-03-05 10:00:00'));
    $withIssues->assertSee('最終活動日');

    $withoutIssues = Livewire::actingAs($outsider)->test('projects.index')
        ->set('displayType', 'list')
        ->set('columns', ['name', 'last_activity_date']);

    expect($withoutIssues->instance()->columnValue($project, 'last_activity_date'))->toBe('');
});

test('the last activity date takes the newest of every event type and skips private notes', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create(['created_at' => '2026-01-01 00:00:00']);
    $issue->journals()->create(['user_id' => User::factory()->create()->id, 'notes' => 'Hidden note', 'private_notes' => true])
        ->forceFill(['created_at' => '2026-05-01 00:00:00'])->save();
    $issue->journals()->create(['user_id' => User::factory()->create()->id, 'notes' => 'Public note', 'private_notes' => false])
        ->forceFill(['created_at' => '2026-02-01 00:00:00'])->save();
    $viewer = leftoverMember($project, ['view_project', 'view_issues']);

    $dates = app(ProjectLastActivity::class)->forProjects(collect([$project]), $viewer);

    expect($dates->get($project->id)->toDateString())->toBe('2026-02-01');
});

test('the last activity date column cannot be sorted', function () {
    Project::factory()->create();

    $list = Livewire::actingAs(User::factory()->create())->test('projects.index')
        ->set('displayType', 'list')
        ->set('columns', ['name', 'last_activity_date'])
        ->call('sortBy', 'last_activity_date');

    expect($list->get('sortKey'))->toBeNull();
});

test('projects.csv exports every matching visible project with the chosen columns, beyond the page', function () {
    foreach (range(1, 3) as $number) {
        Project::factory()->create(['name' => "Visible {$number}", 'identifier' => "visible-{$number}"]);
    }
    Project::factory()->private()->create(['name' => 'Hidden', 'identifier' => 'hidden']);
    Setting::set('per_page_options', '2,25');

    $list = Livewire::withQueryParams(['per_page' => 2])->actingAs(User::factory()->create())->test('projects.index')
        ->set('columns', ['name', 'identifier'])
        ->call('sortBy', 'name');

    expect($list->get('projects')->items())->toHaveCount(2);
    $list->call('exportCsv')
        ->assertFileDownloaded('projects.csv', "\xEF\xBB\xBF".csvRow(['名前', '識別子']).csvRow(['Visible 1', 'visible-1']).csvRow(['Visible 2', 'visible-2']).csvRow(['Visible 3', 'visible-3']));
});

test('projects.csv leaves out a role-restricted custom field column for a non-admin', function () {
    $field = CustomField::factory()->create(['name' => 'Secret rating', 'customized_type' => CustomizableType::Project->value]);
    $field->roles()->attach(Role::factory()->create());
    $project = Project::factory()->create(['name' => 'Rated', 'identifier' => 'rated']);
    auth()->setUser(User::factory()->admin()->create());
    $project->setCustomFieldValues([$field->id => 'Classified-777']);

    Livewire::actingAs(User::factory()->create())->test('projects.index')
        ->set('columns', ['name', "cf_{$field->id}"])
        ->call('exportCsv')
        ->assertFileDownloaded('projects.csv', "\xEF\xBB\xBF".csvRow(['名前']).csvRow(['Rated']));
});

test('board cards render the description as Markdown and show the visible custom fields', function () {
    $field = CustomField::factory()->create(['name' => 'Budget code', 'customized_type' => CustomizableType::Project->value]);
    $secret = CustomField::factory()->create(['name' => 'Secret rating', 'customized_type' => CustomizableType::Project->value]);
    $secret->roles()->attach(Role::factory()->create());
    $project = Project::factory()->create(['name' => 'Documented', 'description' => "Some **bold** text\n\n".str_repeat('x', 300)."\nnext line"]);
    auth()->setUser(User::factory()->admin()->create());
    $project->setCustomFieldValues([$field->id => 'BC-42', $secret->id => 'Classified-777']);

    Livewire::actingAs(User::factory()->create())->test('projects.index')
        ->set('displayType', 'board')
        ->assertSeeHtml('<strong>bold</strong>')
        ->assertSee('Budget code')
        ->assertSee('BC-42')
        ->assertDontSee('next line')
        ->assertDontSee('Secret rating')
        ->assertDontSee('Classified-777');
});

test('a saved project query keeps its display type', function () {
    $user = User::factory()->create();
    Member::factory()->for(Project::factory()->create())->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'save_queries']])
    );

    Livewire::actingAs($user)->test('projects.index')
        ->call('setDisplayType', 'list')
        ->set('newQueryName', 'As a table')
        ->call('saveQuery')
        ->assertHasNoErrors();

    $saved = Query::query()->where('name', 'As a table')->firstOrFail();
    expect($saved->options)->toBe(['display_type' => 'list']);

    Livewire::actingAs($user)->test('projects.index')
        ->call('setDisplayType', 'board')
        ->call('loadQuery', $saved->id)
        ->assertSet('displayType', 'list');
});

test('a saved project query without a display type opens in the site default', function () {
    $user = User::factory()->create();
    $query = Query::query()->create([
        'name' => 'Old query', 'type' => QueryType::Project->value, 'user_id' => $user->id,
        'project_id' => null, 'visibility' => QueryVisibility::Private->value,
        'filters' => [], 'column_names' => ['name'], 'sort_criteria' => [], 'group_by' => null,
    ]);

    Livewire::actingAs($user)->test('projects.index')
        ->call('setDisplayType', 'list')
        ->call('loadQuery', $query->id)
        ->assertSet('displayType', null);
});

test('the admin project list shows the last activity date column unsorted', function () {
    $project = Project::factory()->create(['name' => 'Busy']);
    Issue::factory()->for($project)->create(['created_at' => '2026-03-05 10:00:00']);

    $list = Livewire::actingAs(User::factory()->admin()->create())->test('admin.projects')
        ->set('columns', ['name', 'last_activity_date'])
        ->call('sortBy', 'last_activity_date');

    expect($list->get('sortKey'))->toBeNull();
    $list->assertSee(DateTimes::date('2026-03-05 10:00:00'));
});
