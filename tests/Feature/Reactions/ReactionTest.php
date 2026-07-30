<?php

use App\Enums\RoleBuiltin;
use App\Models\Board;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Message;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Services\ReactionService;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

function reactionProjectMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function reactableIssue(Project $project): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

test('toggling a reaction on an issue adds then removes it, and the rendered count follows it', function () {
    $project = Project::factory()->create();
    $user = reactionProjectMember($project, ['view_issues']);
    $issue = reactableIssue($project);

    $component = Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertSeeHtml('data-reaction="issue:'.$issue->id.':0"')
        // A zero count shows the bare 👍 icon, not a visible "0" —
        // matches Redmine's reaction_button, which omits the counter
        // entirely when nobody has reacted yet.
        ->assertDontSeeHtml('<span>0</span>')
        ->call('toggleReaction', 'issue', $issue->id);

    expect($issue->reactions()->where('user_id', $user->id)->exists())->toBeTrue();

    // Confirms the rendered count actually moves along with the DB row —
    // Livewire re-hydrates $this->issue fresh on every request, so this
    // was verified not to be at risk of showing a stale count, but it's
    // still the button's whole job and worth pinning directly.
    $component->assertSeeHtml('data-reaction="issue:'.$issue->id.':1"')
        ->call('toggleReaction', 'issue', $issue->id)
        ->assertDontSeeHtml('data-reaction="issue:'.$issue->id.':1"');

    expect($issue->reactions()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('toggling a reaction on a journal comment works independently of the issue itself, and the rendered count follows it', function () {
    $project = Project::factory()->create();
    $user = reactionProjectMember($project, ['view_issues']);
    $issue = reactableIssue($project);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $user->id, 'notes' => 'コメント', 'private_notes' => false]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('toggleReaction', 'journal', $journal->id)
        ->assertHasNoErrors()
        ->assertSeeHtml('data-reaction="journal:'.$journal->id.':1"');

    expect($journal->reactions()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('a user cannot react to a private journal note they cannot view', function () {
    $project = Project::factory()->create();
    $author = User::factory()->create();
    $viewer = reactionProjectMember($project, ['view_issues']);
    $issue = reactableIssue($project);
    $journal = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => '非公開', 'private_notes' => true]);

    Livewire::actingAs($viewer)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('toggleReaction', 'journal', $journal->id)
        ->assertForbidden();

    expect($journal->reactions()->exists())->toBeFalse();
});

test('a non-member without view_issues cannot react, but one with it can', function () {
    // Livewire::test's mount() already authorizes 'view' on the issue, so a
    // user without view_issues never reaches the component at all — the
    // gate that matters here is exercised directly through the service
    // instead. A logged-in non-member falls back to the NonMember builtin
    // role (AuthorizationService::rolesFor), so both halves of this test
    // need that role actually registered — otherwise "no role found" and
    // "role found without the permission" are indistinguishable, and the
    // assertion passes for the wrong reason.
    $project = Project::factory()->create();
    $issue = reactableIssue($project);
    $outsider = User::factory()->create();

    expect(app(ReactionService::class)->canReact($outsider, $issue))->toBeFalse();

    Role::factory()->create(['builtin' => RoleBuiltin::NonMember->value, 'permissions' => ['view_issues']]);

    expect(app(ReactionService::class)->canReact($outsider, $issue))->toBeTrue();
});

test('reacting is blocked on a closed project', function () {
    $project = Project::factory()->closed()->create();
    $user = reactionProjectMember($project, ['view_issues']);
    $issue = reactableIssue($project);

    expect(app(ReactionService::class)->canReact($user, $issue))->toBeFalse();
});

test('a journal id from a different, private project is rejected rather than found and reacted to', function () {
    $project = Project::factory()->create();
    $user = reactionProjectMember($project, ['view_issues']);
    $issue = reactableIssue($project);

    $privateProject = Project::factory()->private()->create();
    $privateIssue = reactableIssue($privateProject);
    $foreignJournal = Journal::create(['issue_id' => $privateIssue->id, 'user_id' => $user->id, 'notes' => '他プロジェクト', 'private_notes' => false]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('toggleReaction', 'journal', $foreignJournal->id)
        ->assertForbidden();

    expect($foreignJournal->reactions()->exists())->toBeFalse();
});

test('reactions_enabled=false blocks toggling even for an otherwise-permitted member', function () {
    Setting::set('reactions_enabled', false);
    $project = Project::factory()->create();
    $user = reactionProjectMember($project, ['view_issues']);
    $issue = reactableIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('toggleReaction', 'issue', $issue->id)
        ->assertForbidden();

    expect($issue->reactions()->exists())->toBeFalse();
});

test('toggling a reaction on a news item and its comment', function () {
    $project = Project::factory()->create();
    $user = reactionProjectMember($project, ['view_news']);
    $news = News::factory()->for($project)->create();
    $comment = NewsComment::factory()->for($news)->create();

    Livewire::actingAs($user)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->call('toggleReaction', 'news', $news->id)
        ->assertHasNoErrors()
        ->call('toggleReaction', 'news_comment', $comment->id)
        ->assertHasNoErrors();

    expect($news->reactions()->where('user_id', $user->id)->exists())->toBeTrue();
    expect($comment->reactions()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('toggling a reaction on a forum topic and its reply', function () {
    $project = Project::factory()->create();
    $user = reactionProjectMember($project, ['view_messages']);
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->create(['parent_id' => null]);
    $reply = Message::factory()->for($board)->create(['parent_id' => $topic->id]);

    Livewire::actingAs($user)
        ->test('messages.show', ['project' => $project, 'board' => $board, 'message' => $topic])
        ->call('toggleReaction', 'message', $topic->id)
        ->assertHasNoErrors()
        ->call('toggleReaction', 'message', $reply->id)
        ->assertHasNoErrors();

    expect($topic->reactions()->where('user_id', $user->id)->exists())->toBeTrue();
    expect($reply->reactions()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('the database uniquely constrains one reaction per user per object', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $issue = reactableIssue($project);

    $issue->reactions()->create(['user_id' => $user->id]);

    expect(fn () => $issue->reactions()->create(['user_id' => $user->id]))
        ->toThrow(QueryException::class);
});
