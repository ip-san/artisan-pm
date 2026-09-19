<?php

use App\Models\Board;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Message;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function watcherPermissionMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('the issue watcher list needs view_issue_watchers, adding needs add and removing needs delete', function () {
    $project = Project::factory()->create();
    $blind = watcherPermissionMember($project, ['view_project', 'view_issues']);
    $adder = watcherPermissionMember($project, ['view_project', 'view_issues', 'view_issue_watchers', 'add_issue_watchers']);
    $remover = watcherPermissionMember($project, ['view_project', 'view_issues', 'view_issue_watchers', 'delete_issue_watchers']);
    $target = watcherPermissionMember($project, ['view_project', 'view_issues']);
    $issue = Issue::factory()->for($project)->create();
    $issue->watchers()->create(['user_id' => $target->id]);

    expect($blind->can('viewWatchers', $issue))->toBeFalse()
        ->and($adder->can('addWatchers', $issue))->toBeTrue()
        ->and($adder->can('deleteWatchers', $issue))->toBeFalse()
        ->and($remover->can('deleteWatchers', $issue))->toBeTrue()
        ->and($remover->can('addWatchers', $issue))->toBeFalse();

    Livewire::actingAs($blind)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertDontSee('ウォッチャー (');
    Livewire::actingAs($adder)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertSee('ウォッチャー (')->call('removeWatcher', $target->id)->assertForbidden();
    Livewire::actingAs($remover)->test('issues.show', ['project' => $project, 'issue' => $issue])->call('removeWatcher', $target->id);

    expect($issue->watchers()->count())->toBe(0);
});

test('the issue API only lists watchers for callers with view_issue_watchers', function () {
    $project = Project::factory()->create();
    $blind = watcherPermissionMember($project, ['view_issues']);
    $seer = watcherPermissionMember($project, ['view_issues', 'view_issue_watchers']);
    $issue = Issue::factory()->for($project)->create();
    $issue->watchers()->create(['user_id' => $seer->id]);

    Passport::actingAs($blind);
    expect($this->getJson("/api/v1/issues/{$issue->id}?include=watchers")->assertOk()->json('data'))->not->toHaveKey('watchers');

    Passport::actingAs($seer);
    expect($this->getJson("/api/v1/issues/{$issue->id}?include=watchers")->assertOk()->json('data.watchers'))->toHaveCount(1);
});

test('the API watcher endpoints use add and delete separately', function () {
    $project = Project::factory()->create();
    $adder = watcherPermissionMember($project, ['view_issues', 'add_issue_watchers']);
    $target = watcherPermissionMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create();

    Passport::actingAs($adder);
    $this->postJson("/api/v1/issues/{$issue->id}/watchers", ['user_id' => $target->id])->assertNoContent();
    $this->deleteJson("/api/v1/issues/{$issue->id}/watchers/{$target->id}")->assertForbidden();
});

test('wiki page watchers follow their own three permissions', function () {
    $project = Project::factory()->create();
    $editor = watcherPermissionMember($project, ['view_project', 'view_wiki_pages', 'edit_wiki_pages']);
    $adder = watcherPermissionMember($project, ['view_project', 'view_wiki_pages', 'view_wiki_page_watchers', 'add_wiki_page_watchers']);
    $target = watcherPermissionMember($project, ['view_project', 'view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    expect($editor->can('addWatchers', $page))->toBeFalse()
        ->and($editor->can('viewWatchers', $page))->toBeFalse();

    Livewire::actingAs($adder)->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->set('newWatcherId', $target->id)
        ->call('addWatcher')
        ->assertHasNoErrors();
    expect($page->watchers()->count())->toBe(1);

    Livewire::actingAs($adder)->test('wiki.show', ['project' => $project, 'wikiPage' => $page])->call('removeWatcher', $target->id)->assertForbidden();
});

test('forum topic watchers follow their own three permissions and replies stay unwatchable', function () {
    $project = Project::factory()->create();
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->create();
    $reply = Message::factory()->for($board)->create(['parent_id' => $topic->id]);
    $editor = watcherPermissionMember($project, ['view_project', 'view_messages', 'edit_messages']);
    $manager = watcherPermissionMember($project, ['view_project', 'view_messages', 'view_message_watchers', 'add_message_watchers', 'delete_message_watchers']);
    $target = watcherPermissionMember($project, ['view_project', 'view_messages']);

    expect($editor->can('addWatchers', $topic))->toBeFalse()
        ->and($manager->can('addWatchers', $topic))->toBeTrue()
        ->and($manager->can('deleteWatchers', $topic))->toBeTrue()
        ->and($manager->can('viewWatchers', $topic))->toBeTrue()
        ->and($manager->can('addWatchers', $reply))->toBeFalse()
        ->and($manager->can('viewWatchers', $reply))->toBeFalse();

    Livewire::actingAs($manager)->test('messages.show', ['project' => $project, 'board' => $board, 'message' => $topic])
        ->set('newWatcherId', $target->id)
        ->call('addWatcher')
        ->call('removeWatcher', $target->id);

    expect($topic->watchers()->count())->toBe(0);
});
