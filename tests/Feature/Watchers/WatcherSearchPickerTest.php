<?php

use App\Models\Board;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use Livewire\Livewire;

/**
 * @return array{project: Project, manager: User, alice: User, bob: User}
 */
function watcherPickerSetup(): array
{
    $project = Project::factory()->create();
    $manager = User::factory()->create();
    Member::factory()->for($project)->for($manager)->create()->roles()->attach(Role::factory()->create(['permissions' => [
        'view_issues', 'view_issue_watchers', 'add_issue_watchers', 'view_messages', 'add_messages', 'add_message_watchers', 'view_message_watchers', 'view_news', 'manage_news', 'view_wiki_pages', 'add_wiki_page_watchers', 'view_wiki_page_watchers', 'manage_wiki',
    ]]));
    $alice = User::factory()->create(['name' => 'Alice Anderson', 'email' => 'alice@corp.test']);
    $bob = User::factory()->create(['name' => 'Bob Brown', 'email' => 'bob@other.test']);
    Member::factory()->for($project)->for($alice)->create();
    Member::factory()->for($project)->for($bob)->create();

    return compact('project', 'manager', 'alice', 'bob');
}

test('the issue watcher picker filters members by name or email and adds the picked one', function () {
    ['project' => $project, 'manager' => $manager, 'alice' => $alice, 'bob' => $bob] = watcherPickerSetup();
    $issue = Issue::factory()->for($project)->create();

    $page = Livewire::actingAs($manager)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertSee('data-watcher-search', false);

    expect($page->set('watcherSearch', 'alice')->get('watcherCandidates')->pluck('id')->all())->toBe([$alice->id]);
    expect($page->set('watcherSearch', 'OTHER.TEST')->get('watcherCandidates')->pluck('id')->all())->toBe([$bob->id]);

    $page->set('watcherSearch', 'alice')->call('pickWatcher', $alice->id);
    expect($issue->watchers()->pluck('user_id')->all())->toBe([$alice->id]);
});

test('an already-watching member is no longer offered, and a search with no hit keeps the box', function () {
    ['project' => $project, 'manager' => $manager, 'alice' => $alice] = watcherPickerSetup();
    $issue = Issue::factory()->for($project)->create();
    $issue->watchers()->create(['user_id' => $alice->id]);

    $page = Livewire::actingAs($manager)->test('issues.show', ['project' => $project, 'issue' => $issue->fresh()])->set('watcherSearch', 'alice');

    expect($page->get('watcherCandidates'))->toHaveCount(0);
    $page->assertSee('data-watcher-search', false);
});

test('picking a non-member is refused by the existing validation', function () {
    ['project' => $project, 'manager' => $manager] = watcherPickerSetup();
    $issue = Issue::factory()->for($project)->create();
    $outsider = User::factory()->create();

    Livewire::actingAs($manager)->test('issues.show', ['project' => $project, 'issue' => $issue])->call('pickWatcher', $outsider->id)->assertHasErrors(['newWatcherId']);

    expect($issue->watchers()->count())->toBe(0);
});

test('the search is capped at ten candidates', function () {
    ['project' => $project, 'manager' => $manager] = watcherPickerSetup();
    $issue = Issue::factory()->for($project)->create();
    foreach (range(1, 14) as $n) {
        Member::factory()->for($project)->for(User::factory()->create(['name' => "Crowd {$n}"]))->create();
    }

    $page = Livewire::actingAs($manager)->test('issues.show', ['project' => $project, 'issue' => $issue])->set('watcherSearch', 'crowd');

    expect($page->get('watcherCandidates'))->toHaveCount(10);
});

test('the same picker works for forum topics, news and wiki pages', function () {
    ['project' => $project, 'manager' => $manager, 'alice' => $alice] = watcherPickerSetup();
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->create(['author_id' => $manager->id]);
    $news = News::factory()->for($project)->create();
    $wiki = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($manager)->test('messages.show', ['project' => $project, 'board' => $board, 'message' => $topic])->set('watcherSearch', 'alice')->call('pickWatcher', $alice->id);
    Livewire::actingAs($manager)->test('news.show', ['project' => $project, 'news' => $news])->set('watcherSearch', 'alice')->call('pickWatcher', $alice->id);
    Livewire::actingAs($manager)->test('wiki.show', ['project' => $project, 'wikiPage' => $wiki])->set('watcherSearch', 'alice')->call('pickWatcher', $alice->id);

    expect($topic->watchers()->pluck('user_id')->all())->toBe([$alice->id])
        ->and($news->watchers()->pluck('user_id')->all())->toBe([$alice->id])
        ->and($wiki->watchers()->pluck('user_id')->all())->toBe([$alice->id]);
});
