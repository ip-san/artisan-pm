<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function newItemMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('the plus menu lists exactly what the viewer may create in the project', function () {
    $project = Project::factory()->create();
    $user = newItemMember($project, ['view_project', 'view_issues', 'add_issues', 'log_time', 'manage_versions']);

    $html = $this->actingAs($user)->get(route('projects.show', $project))->assertOk()->getContent();

    expect($html)->toContain('data-new-item-menu="dropdown"')
        ->toContain(route('issues.create', $project))
        ->toContain(route('time-entries.create', $project))
        ->toContain(route('versions.create', $project))
        ->not->toContain(route('news.create', $project))
        ->not->toContain(route('wiki.create', $project));
});

test('the menu is absent outside a project and for a member who can create nothing', function () {
    $project = Project::factory()->create();
    $user = newItemMember($project, ['view_project']);

    expect($this->actingAs($user)->get(route('projects.show', $project))->getContent())->not->toContain('data-new-item-menu')
        ->and($this->actingAs($user)->get(route('projects.index'))->getContent())->not->toContain('data-new-item-menu');
});

test('setting 1 shows only a new issue link and setting 0 shows nothing', function () {
    $project = Project::factory()->create();
    $user = newItemMember($project, ['view_project', 'view_issues', 'add_issues', 'log_time']);

    Setting::set('new_item_menu_tab', '1');
    $single = $this->actingAs($user)->get(route('projects.show', $project))->getContent();
    expect($single)->toContain('data-new-item-menu="issue"')->not->toContain('data-new-item-menu="dropdown"')->not->toContain(route('time-entries.create', $project));

    Setting::set('new_item_menu_tab', '0');
    expect($this->actingAs($user)->get(route('projects.show', $project))->getContent())->not->toContain('data-new-item-menu');
});

test('setting 1 shows no link to someone who cannot add issues', function () {
    $project = Project::factory()->create();
    $user = newItemMember($project, ['view_project', 'log_time']);
    Setting::set('new_item_menu_tab', '1');

    expect($this->actingAs($user)->get(route('projects.show', $project))->getContent())->not->toContain('data-new-item-menu');
});

test('the settings form saves the menu mode and rejects anything else', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('new_item_menu_tab', '1')->call('save')->assertHasNoErrors();
    expect(Setting::get('new_item_menu_tab'))->toBe('1');

    Livewire::actingAs($admin)->test('settings.index')->set('new_item_menu_tab', '9')->call('save')->assertHasErrors(['new_item_menu_tab']);
});
