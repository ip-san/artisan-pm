<?php

use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Preferences\UserPreferences;
use App\Support\Query\DefaultProjectQuery;
use Livewire\Livewire;

function projectQuerySaver(): User
{
    $user = User::factory()->create();
    Member::factory()->for(Project::factory()->create())->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'save_queries']])
    );

    return $user;
}

/**
 * @param  array<string, array{operator: string, values: array<int, string>}>  $filters
 * @param  array<int, string>  $columns
 */
function projectQuery(string $name, QueryVisibility $visibility, ?User $owner = null, array $filters = [], array $columns = ['name'], QueryType $type = QueryType::Project): Query
{
    return Query::query()->create([
        'name' => $name, 'type' => $type->value, 'user_id' => ($owner ?? User::factory()->create())->id,
        'project_id' => null, 'visibility' => $visibility->value,
        'filters' => $filters, 'column_names' => $columns, 'sort_criteria' => [], 'group_by' => null,
    ]);
}

test('a user with save_queries saves the current filters, columns and sort as a private global project query', function () {
    $user = projectQuerySaver();

    Livewire::actingAs($user)->test('projects.index')
        ->set('activeFilterKeys', ['name'])
        ->set('filterOperators', ['name' => '~'])
        ->set('filterValues', ['name' => ['Alpha']])
        ->set('columns', ['name', 'status'])
        ->call('sortBy', 'name')
        ->set('newQueryName', 'Alphas')
        ->set('newQueryVisibility', 'public')
        ->call('saveQuery')
        ->assertHasNoErrors();

    $query = Query::query()->where('name', 'Alphas')->sole();

    expect($query->type)->toBe(QueryType::Project)
        ->and($query->project_id)->toBeNull()
        ->and($query->visibility)->toBe(QueryVisibility::Private)
        ->and($query->filters)->toBe(['name' => ['operator' => '~', 'values' => ['Alpha']]])
        ->and($query->column_names)->toBe(['name', 'status'])
        ->and($query->sort_criteria)->toBe([['name', 'asc']]);
});

test('an administrator may save a public project query', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('projects.index')
        ->set('newQueryName', 'Shared')
        ->set('newQueryVisibility', 'public')
        ->call('saveQuery')
        ->assertHasNoErrors();

    expect(Query::query()->where('name', 'Shared')->sole()->visibility)->toBe(QueryVisibility::Public);
});

test('a user without save_queries cannot save a project query', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('projects.index')
        ->assertDontSee(__('クエリを保存'))
        ->set('newQueryName', 'Nope')
        ->call('saveQuery')
        ->assertForbidden();

    expect(Query::query()->count())->toBe(0);
});

test('loading a saved query applies its filters and columns', function () {
    Project::factory()->create(['name' => 'Alpha']);
    Project::factory()->create(['name' => 'Beta']);
    $user = User::factory()->create();
    $query = projectQuery('Alphas', QueryVisibility::Public, null, ['name' => ['operator' => '~', 'values' => ['Alp']]], ['name', 'status']);

    $component = Livewire::actingAs($user)->test('projects.index')
        ->assertSee('Alphas')
        ->call('loadQuery', $query->id);

    expect($component->get('visibleColumns'))->toBe(['name', 'status'])
        ->and(collect($component->get('projects')->items())->pluck('name')->all())->toBe(['Alpha']);
});

test('another user\'s private project query is neither listed nor loadable', function () {
    $user = User::factory()->create();
    $query = projectQuery('Someone else\'s', QueryVisibility::Private);

    Livewire::actingAs($user)->test('projects.index')
        ->assertDontSee('Someone else\'s')
        ->call('loadQuery', $query->id)
        ->assertForbidden();
});

test('an issue query cannot be loaded into the project list', function () {
    $user = User::factory()->create();
    $issueQuery = projectQuery('Issue one', QueryVisibility::Public, null, [], ['subject'], QueryType::Issue);

    Livewire::actingAs($user)->test('projects.index')
        ->assertDontSee('Issue one')
        ->call('loadQuery', $issueQuery->id)
        ->assertNotFound();
});

test('the user default beats the site default, which must be public', function () {
    $user = User::factory()->create();
    $site = projectQuery('Site', QueryVisibility::Public);
    $mine = projectQuery('Mine', QueryVisibility::Private, $user);

    expect(DefaultProjectQuery::for($user))->toBeNull();

    Setting::set('default_project_query', $site->id);
    expect(DefaultProjectQuery::for($user)->name)->toBe('Site');

    UserPreferences::save($user, ['default_project_query' => $mine->id]);
    expect(DefaultProjectQuery::for($user->fresh())->name)->toBe('Mine');

    $privateSite = projectQuery('Private site', QueryVisibility::Private);
    Setting::set('default_project_query', $privateSite->id);
    UserPreferences::save($user, ['default_project_query' => null]);
    expect(DefaultProjectQuery::for($user->fresh()))->toBeNull();
});

test('a user default they cannot see, or an issue query, is skipped', function () {
    $user = User::factory()->create();
    $others = projectQuery('Not yours', QueryVisibility::Private);
    $issueQuery = projectQuery('Issue one', QueryVisibility::Public, null, [], ['subject'], QueryType::Issue);
    UserPreferences::save($user, ['default_project_query' => $others->id]);
    Setting::set('default_project_query', $issueQuery->id);

    expect(DefaultProjectQuery::for($user->fresh()))->toBeNull();
});

test('the project list opens on the default query unless the URL names its own state', function () {
    $user = User::factory()->create();
    $site = projectQuery('Site', QueryVisibility::Public, null, [], ['name', 'is_public']);
    Setting::set('default_project_query', $site->id);

    expect(Livewire::actingAs($user)->test('projects.index')->get('visibleColumns'))->toBe(['name', 'is_public'])
        ->and(Livewire::withQueryParams(['columns' => ['identifier']])->actingAs($user)->test('projects.index')->get('visibleColumns'))->toBe(['identifier']);
});

test('the profile page stores a visible project query as the personal default', function () {
    $user = User::factory()->create();
    $public = projectQuery('Public one', QueryVisibility::Public);
    $hidden = projectQuery('Hidden one', QueryVisibility::Private);

    Livewire::actingAs($user)->test('profile.index')->set('default_project_query', $public->id)->call('savePreferences')->assertHasNoErrors();
    expect($user->fresh()->preference('default_project_query'))->toBe($public->id);

    Livewire::actingAs($user)->test('profile.index')->set('default_project_query', $hidden->id)->call('savePreferences')->assertHasErrors(['default_project_query']);
});

test('the settings form stores a public project query as the site default only', function () {
    $admin = User::factory()->admin()->create();
    $public = projectQuery('Site public', QueryVisibility::Public);
    $private = projectQuery('Site private', QueryVisibility::Private);
    $issueQuery = projectQuery('Issue one', QueryVisibility::Public, null, [], ['subject'], QueryType::Issue);

    Livewire::actingAs($admin)->test('settings.index')->set('default_project_query', $public->id)->call('save')->assertHasNoErrors();
    expect((int) Setting::get('default_project_query'))->toBe($public->id);

    Livewire::actingAs($admin)->test('settings.index')->set('default_project_query', $private->id)->call('save')->assertHasErrors(['default_project_query']);
    Livewire::actingAs($admin)->test('settings.index')->set('default_project_query', $issueQuery->id)->call('save')->assertHasErrors(['default_project_query']);
});
