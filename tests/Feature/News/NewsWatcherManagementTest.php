<?php

use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Watcher;
use Livewire\Livewire;

function newsWatcherProjectMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with manage_news can add another member as a watcher', function () {
    $project = Project::factory()->create();
    $manager = newsWatcherProjectMember($project, ['view_news', 'manage_news']);
    $target = newsWatcherProjectMember($project, ['view_news']);
    $news = News::factory()->for($project)->create();

    Livewire::actingAs($manager)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->set('newWatcherId', $target->id)
        ->call('addWatcher')
        ->assertHasNoErrors();

    expect(Watcher::where('watchable_id', $news->id)->where('user_id', $target->id)->exists())->toBeTrue();
});

test('a member without manage_news cannot add another user as a watcher', function () {
    $project = Project::factory()->create();
    $user = newsWatcherProjectMember($project, ['view_news']);
    $target = newsWatcherProjectMember($project, ['view_news']);
    $news = News::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->set('newWatcherId', $target->id)
        ->call('addWatcher')
        ->assertForbidden();

    expect(Watcher::where('watchable_id', $news->id)->where('user_id', $target->id)->exists())->toBeFalse();
});

test('a manager can remove another watcher from a news item', function () {
    $project = Project::factory()->create();
    $manager = newsWatcherProjectMember($project, ['view_news', 'manage_news']);
    $watching = newsWatcherProjectMember($project, ['view_news']);
    $news = News::factory()->for($project)->create();
    $news->watchers()->create(['user_id' => $watching->id]);

    Livewire::actingAs($manager)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->call('removeWatcher', $watching->id);

    expect(Watcher::where('watchable_id', $news->id)->where('user_id', $watching->id)->exists())->toBeFalse();
});

test('a non-member of the project cannot be added as a news watcher', function () {
    $project = Project::factory()->create();
    $manager = newsWatcherProjectMember($project, ['view_news', 'manage_news']);
    $outsider = User::factory()->create();
    $news = News::factory()->for($project)->create();

    Livewire::actingAs($manager)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->set('newWatcherId', $outsider->id)
        ->call('addWatcher')
        ->assertHasErrors(['newWatcherId']);
});
