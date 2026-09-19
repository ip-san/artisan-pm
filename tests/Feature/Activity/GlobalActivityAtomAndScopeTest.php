<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

function globalAtomMember(Project $project, array $permissions = ['view_project', 'view_issues', 'view_news']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('the global atom feed lists activity from every visible project and hides the rest', function () {
    $alpha = Project::factory()->create();
    $beta = Project::factory()->create();
    $secret = Project::factory()->private()->create();
    $user = globalAtomMember($alpha);
    Member::factory()->for($beta)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_issues']]));
    Issue::factory()->for($alpha)->create(['subject' => 'Alpha issue', 'created_at' => now()->subDay()]);
    Issue::factory()->for($beta)->create(['subject' => 'Beta issue', 'created_at' => now()->subDay()]);
    Issue::factory()->for($secret)->create(['subject' => 'Secret issue', 'created_at' => now()->subDay()]);

    $response = $this->actingAs($user)->get(route('activity.global-atom'));

    $response->assertOk()->assertSee('Alpha issue', false)->assertSee('Beta issue', false)->assertDontSee('Secret issue', false);
    expect($response->headers->get('Content-Type'))->toStartWith('application/atom+xml');
});

test('the feed reader can use the atom key and a guest is sent to login', function () {
    $project = Project::factory()->private()->create();
    $user = globalAtomMember($project);
    Issue::factory()->for($project)->create(['subject' => 'Via key', 'created_at' => now()->subDay()]);

    $this->get(route('activity.global-atom'))->assertRedirect(route('login'));
    $this->get(route('activity.global-atom', ['key' => $user->atomKey()]))->assertOk()->assertSee('Via key', false);
});

test('the feed honours feeds_limit and the activity window', function () {
    Setting::set('feeds_limit', 1);
    $project = Project::factory()->create();
    $user = globalAtomMember($project);
    Issue::factory()->for($project)->create(['subject' => 'Newest one', 'created_at' => now()->subHours(2)]);
    Issue::factory()->for($project)->create(['subject' => 'Older one', 'created_at' => now()->subDay()]);
    Issue::factory()->for($project)->create(['subject' => 'Ancient one', 'created_at' => now()->subDays(60)]);

    $this->actingAs($user)->get(route('activity.global-atom'))->assertSee('Newest one', false)->assertDontSee('Older one', false)->assertDontSee('Ancient one', false);
});

test('applying the type filter remembers it as activity_scope and reopens with it', function () {
    $project = Project::factory()->create();
    $user = globalAtomMember($project);

    Livewire::actingAs($user)->test('activity.global-index')->set('activeTypes', ['news'])->call('applyFilters');
    expect($user->fresh()->preference('activity_scope'))->toBe(['news']);

    expect(Livewire::actingAs($user->fresh())->test('activity.global-index')->get('activeTypes'))->toBe(['news']);
});

test('the project activity page remembers the scope too, and unknown types are dropped on reopening', function () {
    $project = Project::factory()->create();
    $user = globalAtomMember($project);

    Livewire::actingAs($user)->test('activity.index', ['project' => $project])->set('activeTypes', ['issue', 'gone-type'])->call('applyFilters');

    expect(Livewire::actingAs($user->fresh())->test('activity.index', ['project' => $project])->get('activeTypes'))->toBe(['issue']);
});

test('the feed follows the remembered scope', function () {
    $project = Project::factory()->create();
    $user = globalAtomMember($project);
    Issue::factory()->for($project)->create(['subject' => 'An issue entry', 'created_at' => now()->subDay()]);
    News::factory()->for($project)->create(['title' => 'A news entry', 'created_at' => now()->subDay()]);

    Livewire::actingAs($user)->test('activity.global-index')->set('activeTypes', ['news'])->call('applyFilters');

    $this->actingAs($user->fresh())->get(route('activity.global-atom'))->assertSee('A news entry', false)->assertDontSee('An issue entry', false);
});

test('without a remembered scope the defaults apply, and a guest keeps them too', function () {
    $project = Project::factory()->create();
    $user = globalAtomMember($project);

    expect($user->preference('activity_scope'))->toBe([])
        ->and(Livewire::actingAs($user)->test('activity.global-index')->get('activeTypes'))->toContain('issue');
});
