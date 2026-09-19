<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Dashboard\Blocks\CalendarBlock;
use App\Support\Dashboard\Blocks\UpdatedByMeBlock;
use Livewire\Livewire;

function blockMember(Project $project, string $visibility = 'all'): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => $visibility]));

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function blockIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

test('the updated-by-me block lists issues the user commented on, newest first, once each', function () {
    $project = Project::factory()->create();
    $user = blockMember($project);
    $older = blockIssue($project, ['subject' => 'Older touch']);
    $newer = blockIssue($project, ['subject' => 'Newer touch']);
    $untouched = blockIssue($project, ['subject' => 'Untouched']);
    foreach ([[$older, 'a', now()->subDays(3)], [$newer, 'b', now()->subDay()], [$newer, 'c', now()->subHour()]] as [$issue, $notes, $at]) {
        Journal::create(['issue_id' => $issue->id, 'user_id' => $user->id, 'notes' => $notes])->forceFill(['created_at' => $at])->save();
    }
    Journal::create(['issue_id' => $untouched->id, 'user_id' => User::factory()->create()->id, 'notes' => 'other person']);

    $titles = app(UpdatedByMeBlock::class)->rows($user)->pluck('title')->all();

    expect($titles)->toHaveCount(2)->and($titles[0])->toContain('Newer touch')->and($titles[1])->toContain('Older touch');
});

test('the updated-by-me block hides issues the user may no longer see', function () {
    $project = Project::factory()->create();
    $user = blockMember($project, 'own');
    $hidden = blockIssue($project, ['subject' => 'Now hidden', 'author_id' => User::factory()->create()->id, 'assigned_to_id' => null]);
    Journal::create(['issue_id' => $hidden->id, 'user_id' => $user->id, 'notes' => 'was allowed once']);

    expect(app(UpdatedByMeBlock::class)->rows($user))->toHaveCount(0);
});

test('the calendar block lists open issues of the user\'s projects that start or fall due this week', function () {
    $project = Project::factory()->create();
    $user = blockMember($project);
    blockIssue($project, ['subject' => 'Due soon', 'due_date' => now()->addDays(2)->toDateString()]);
    blockIssue($project, ['subject' => 'Starts today', 'start_date' => now()->toDateString()]);
    blockIssue($project, ['subject' => 'Too far', 'due_date' => now()->addDays(30)->toDateString()]);
    blockIssue($project, ['subject' => 'Past due', 'due_date' => now()->subDays(3)->toDateString()]);
    blockIssue($project, ['subject' => 'Closed', 'due_date' => now()->addDay()->toDateString(), 'status_id' => IssueStatus::factory()->create(['is_closed' => true])->id]);
    blockIssue(Project::factory()->create(), ['subject' => 'Other project', 'due_date' => now()->addDay()->toDateString()]);

    $rows = app(CalendarBlock::class)->rows($user);
    $titles = $rows->pluck('title')->implode(' | ');

    expect($rows)->toHaveCount(2)->and($titles)->toContain('Starts today')->toContain('Due soon')
        ->and($rows->first()->title)->toContain('Starts today')
        ->and($rows->last()->meta)->toContain('期日');
});

test('both blocks can be added to the my page and render', function () {
    $project = Project::factory()->create();
    $user = blockMember($project);
    blockIssue($project, ['subject' => 'Shown in calendar', 'due_date' => now()->addDay()->toDateString()]);

    $page = Livewire::actingAs($user)->test('my-page.index')->call('addBlock', 'updated_by_me')->call('addBlock', 'calendar');

    expect($page->get('activeBlocks')->pluck('block_key'))->toContain('updated_by_me', 'calendar');
    $page->assertSee('自分が更新した課題')->assertSee('今週のカレンダー')->assertSee('Shown in calendar');
});
