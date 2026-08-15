<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;

test('a guest hitting the root is redirected to login via the projects route', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('projects.index'));

    $response = $this->get(route('projects.index'));

    $response->assertRedirect(route('login'));
});

test('a child model nested under a project it does not belong to 404s instead of rendering', function () {
    // Without ->scopeBindings() Laravel resolved {project} and {issue}
    // independently, so this URL rendered the page with $project set to the
    // unrelated project — and issues/show then validated its inputs (the
    // watcher candidate list, most visibly) against that wrong project,
    // letting a non-member be attached as a watcher.
    $owner = Project::factory()->create();
    $unrelated = Project::factory()->create();

    $user = User::factory()->admin()->create();

    $issue = Issue::factory()->for($owner)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);

    $this->actingAs($user)
        ->get(route('issues.show', [$owner, $issue]))
        ->assertOk();

    $this->actingAs($user)
        ->get("/projects/{$unrelated->identifier}/issues/{$issue->id}")
        ->assertNotFound();
});
