<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Watcher;
use App\Models\WikiPage;
use Livewire\Livewire;

function wikiWatcherProjectMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with edit_wiki_pages can add another member as a watcher', function () {
    $project = Project::factory()->create();
    $manager = wikiWatcherProjectMember($project, ['view_wiki_pages', 'edit_wiki_pages']);
    $target = wikiWatcherProjectMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($manager)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->set('newWatcherId', $target->id)
        ->call('addWatcher')
        ->assertHasNoErrors();

    expect(Watcher::where('watchable_id', $page->id)->where('user_id', $target->id)->exists())->toBeTrue();
});

test('a member without edit_wiki_pages cannot add another user as a watcher', function () {
    $project = Project::factory()->create();
    $user = wikiWatcherProjectMember($project, ['view_wiki_pages']);
    $target = wikiWatcherProjectMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->set('newWatcherId', $target->id)
        ->call('addWatcher')
        ->assertForbidden();

    expect(Watcher::where('watchable_id', $page->id)->where('user_id', $target->id)->exists())->toBeFalse();
});

test('a manager can remove another watcher from a wiki page', function () {
    $project = Project::factory()->create();
    $manager = wikiWatcherProjectMember($project, ['view_wiki_pages', 'edit_wiki_pages']);
    $watching = wikiWatcherProjectMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();
    $page->watchers()->create(['user_id' => $watching->id]);

    Livewire::actingAs($manager)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('removeWatcher', $watching->id);

    expect(Watcher::where('watchable_id', $page->id)->where('user_id', $watching->id)->exists())->toBeFalse();
});

test('a non-member of the project cannot be added as a wiki page watcher', function () {
    $project = Project::factory()->create();
    $manager = wikiWatcherProjectMember($project, ['view_wiki_pages', 'edit_wiki_pages']);
    $outsider = User::factory()->create();
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($manager)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->set('newWatcherId', $outsider->id)
        ->call('addWatcher')
        ->assertHasErrors(['newWatcherId']);
});
