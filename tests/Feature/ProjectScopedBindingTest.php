<?php

use App\Models\Board;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\WikiPage;
use Livewire\Livewire;

/**
 * Every one of these components takes a child model alongside {project}, and
 * validates its own inputs (assignable members, selectable categories,
 * trackers, versions...) against $this->project. The routes scope their
 * bindings, so a mismatched URL never reaches them — these assertions pin the
 * components' own guards, so they stay correct if a future route forgets to.
 */
function scopedBindingAdmin(): User
{
    return User::factory()->admin()->create();
}

function scopedBindingIssue(Project $project): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

test('a component mounted with a child belonging to another project 404s', function (string $component, callable $make) {
    $owner = Project::factory()->create();
    $unrelated = Project::factory()->create();
    $admin = scopedBindingAdmin();

    $params = $make($owner, $admin);

    // Sanity: the honest pairing renders.
    Livewire::actingAs($admin)->test($component, ['project' => $owner, ...$params]);

    // The mismatched pairing must not.
    Livewire::actingAs($admin)
        ->test($component, ['project' => $unrelated, ...$params])
        ->assertNotFound();
})->with([
    'news.show' => ['news.show', fn (Project $p, User $u) => [
        'news' => News::factory()->for($p)->create(['author_id' => $u->id]),
    ]],
    'wiki.show' => ['wiki.show', fn (Project $p, User $u) => [
        'wikiPage' => WikiPage::factory()->for($p)->create(),
    ]],
    'wiki.form' => ['wiki.form', fn (Project $p, User $u) => [
        'wikiPage' => WikiPage::factory()->for($p)->create(),
    ]],
    'time-entries.form' => ['time-entries.form', fn (Project $p, User $u) => [
        'timeEntry' => TimeEntry::factory()->for($p)->create(['user_id' => $u->id]),
    ]],
    'issues.form' => ['issues.form', fn (Project $p, User $u) => [
        'issue' => scopedBindingIssue($p),
    ]],
]);

test('messages.show 404s when the board or the topic belongs elsewhere', function () {
    $owner = Project::factory()->create();
    $unrelated = Project::factory()->create();
    $admin = scopedBindingAdmin();

    $board = Board::factory()->for($owner)->create();
    $topic = Message::factory()->for($board)->create(['author_id' => $admin->id]);

    Livewire::actingAs($admin)->test('messages.show', ['project' => $owner, 'board' => $board, 'message' => $topic]);

    // Board reached through the wrong project.
    Livewire::actingAs($admin)
        ->test('messages.show', ['project' => $unrelated, 'board' => $board, 'message' => $topic])
        ->assertNotFound();

    // Topic reached through the wrong board.
    $otherBoard = Board::factory()->for($owner)->create();
    Livewire::actingAs($admin)
        ->test('messages.show', ['project' => $owner, 'board' => $otherBoard, 'message' => $topic])
        ->assertNotFound();
});
