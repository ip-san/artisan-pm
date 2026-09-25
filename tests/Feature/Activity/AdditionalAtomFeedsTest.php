<?php

use App\Models\Changeset;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;

/**
 * A15-16: the four Atom feeds Redmine has that this app didn't — one
 * issue's own journal history, the project list, one repository's
 * revisions, and the global news feed.
 */
function additionalAtomMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

// --- single-issue journal atom (issues.show-atom) ---

test('a member with view_issues can fetch one issue\'s own journal history as an atom feed', function () {
    $project = Project::factory()->create();
    $user = additionalAtomMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Feed subject',
    ]);
    $issue->journals()->create(['user_id' => $user->id, 'notes' => 'A visible comment']);

    $response = $this->actingAs($user)->get(route('issues.show-atom', [$project, $issue]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/atom+xml');
    $response->assertSee('<feed', false)->assertSee('A visible comment', false)->assertSee('Feed subject', false);
});

test('one issue\'s journal atom feed hides a private note from a reader without view_private_notes, except its author', function () {
    $project = Project::factory()->create();
    $author = additionalAtomMember($project, ['view_issues']);
    $reader = additionalAtomMember($project, ['view_issues']);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $issue->journals()->create(['user_id' => $author->id, 'notes' => 'Secret note', 'private_notes' => true]);

    $this->actingAs($reader)->get(route('issues.show-atom', [$project, $issue]))->assertOk()->assertDontSee('Secret note', false);
    $this->actingAs($author)->get(route('issues.show-atom', [$project, $issue]))->assertOk()->assertSee('Secret note', false);
});

test('a reader who may not view the issue cannot fetch its journal atom feed', function () {
    $project = Project::factory()->private()->create();
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('issues.show-atom', [$project, $issue]))->assertForbidden();
});

// --- project list atom (projects.atom) ---

test('the project list atom feed lists only projects the reader may see, newest first', function () {
    $mine = Project::factory()->create(['name' => 'Mine visible', 'created_at' => now()]);
    $hidden = Project::factory()->private()->create(['name' => 'Hidden project', 'created_at' => now()->subMinute()]);
    $olderPublic = Project::factory()->create(['name' => 'Older public', 'created_at' => now()->subDay()]);
    $user = additionalAtomMember($mine, ['view_project']);

    $response = $this->actingAs($user)->get(route('projects.atom'))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/atom+xml');
    $response->assertSee('Mine visible', false)->assertSee('Older public', false)->assertDontSee('Hidden project', false);
    $response->assertSeeInOrder(['Mine visible', 'Older public'], false);
});

test('a guest can fetch the project list atom feed when login is not required', function () {
    Setting::set('login_required', false);
    Project::factory()->create(['name' => 'Public one']);

    $this->get(route('projects.atom'))->assertOk()->assertSee('Public one', false);
});

// --- repository revisions atom (repository.revisions-atom) ---

test('a member with view_changesets can fetch the repository revisions atom feed, newest first', function () {
    $project = Project::factory()->create();
    $user = additionalAtomMember($project, ['view_changesets']);
    $repository = Repository::factory()->for($project)->create();
    $older = Changeset::factory()->for($repository)->create(['comments' => 'Older commit', 'committed_on' => now()->subDay()]);
    $newer = Changeset::factory()->for($repository)->create(['comments' => 'Newer commit', 'committed_on' => now()]);

    $response = $this->actingAs($user)->get(route('repository.revisions-atom', $project));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/atom+xml');
    $response->assertSee('Newer commit', false)->assertSee('Older commit', false);
    $response->assertSeeInOrder(['Newer commit', 'Older commit'], false);
});

test('a member without view_changesets cannot fetch the repository revisions atom feed', function () {
    $project = Project::factory()->create();
    $user = additionalAtomMember($project, []);
    Repository::factory()->for($project)->create();

    $this->actingAs($user)->get(route('repository.revisions-atom', $project))->assertForbidden();
});

test('the repository revisions atom feed 404s when the project has no repository', function () {
    $project = Project::factory()->create();
    $user = additionalAtomMember($project, ['view_changesets']);

    $this->actingAs($user)->get(route('repository.revisions-atom', $project))->assertNotFound();
});

// --- global news atom (news.global-atom) ---

test('the global news atom feed lists news from every visible project and hides the rest', function () {
    $mine = Project::factory()->create(['name' => 'Mine']);
    $hidden = Project::factory()->private()->create(['name' => 'Hidden']);
    $user = additionalAtomMember($mine, ['view_news']);
    News::factory()->for($mine)->create(['title' => 'Mine news']);
    News::factory()->for($hidden)->create(['title' => 'Hidden news']);

    $response = $this->actingAs($user)->get(route('news.global-atom'))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/atom+xml');
    $response->assertSee('Mine news', false)->assertDontSee('Hidden news', false);
});

test('the global news atom feed needs a login or atom key when login is required', function () {
    Setting::set('login_required', true);

    $this->get(route('news.global-atom'))->assertRedirect(route('login'));
});
