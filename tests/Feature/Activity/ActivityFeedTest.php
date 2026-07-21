<?php

use App\Models\Board;
use App\Models\Changeset;
use App\Models\Document;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WikiPage;
use Livewire\Livewire;

function activityMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member sees issue creation and issue update entries within the date range', function () {
    $project = Project::factory()->create();
    $user = activityMember($project, ['view_project', 'view_issues']);
    $issue = Issue::factory()->for($project)->create(['created_at' => now()->subDay()]);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $user->id, 'notes' => 'progress update', 'created_at' => now()->subHours(2)]);

    $component = Livewire::actingAs($user)->test('activity.index', ['project' => $project]);
    $titles = $component->get('entries')->pluck('type');

    expect($titles)->toContain('issue')->toContain('issue-edit');
});

test('an issue created outside the date range is excluded', function () {
    $project = Project::factory()->create();
    $user = activityMember($project, ['view_project', 'view_issues']);
    Issue::factory()->for($project)->create(['created_at' => now()->subDays(30)]);

    $component = Livewire::actingAs($user)
        ->test('activity.index', ['project' => $project])
        ->set('from', now()->subDays(7)->toDateString())
        ->set('to', now()->toDateString())
        ->call('applyFilters');

    expect($component->get('entries'))->toHaveCount(0);
});

test('a private journal note is excluded from the feed', function () {
    $project = Project::factory()->create();
    $user = activityMember($project, ['view_project', 'view_issues']);
    $issue = Issue::factory()->for($project)->create(['created_at' => now()->subDays(10)]);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $user->id, 'notes' => 'internal only', 'private_notes' => true]);

    $component = Livewire::actingAs($user)->test('activity.index', ['project' => $project]);

    expect($component->get('entries')->pluck('type'))->not->toContain('issue-edit');
});

test('a member without view_issues sees no issue entries, but still sees news', function () {
    $project = Project::factory()->create();
    $user = activityMember($project, ['view_project', 'view_news']);
    Issue::factory()->for($project)->create();
    News::factory()->for($project)->create();

    $component = Livewire::actingAs($user)->test('activity.index', ['project' => $project]);
    $types = $component->get('entries')->pluck('type');

    expect($types)->not->toContain('issue')
        ->and($types)->toContain('news');
});

test('wiki edits, forum messages, documents, changesets, and time entries all appear', function () {
    $project = Project::factory()->create();
    $user = activityMember($project, [
        'view_project', 'view_wiki_pages', 'view_messages', 'view_documents', 'view_changesets', 'view_time_entries',
    ]);

    $wikiPage = WikiPage::factory()->for($project)->create();
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->create();
    Document::factory()->for($project)->create();
    $repository = Repository::factory()->for($project)->create();
    Changeset::factory()->for($repository)->create(['committed_on' => now()->subDay()]);
    TimeEntry::factory()->for($project)->for($user)->create(['spent_on' => now()->toDateString()]);

    $component = Livewire::actingAs($user)->test('activity.index', ['project' => $project]);
    $types = $component->get('entries')->pluck('type');

    expect($types)->toContain('wiki-edit')
        ->and($types)->toContain('message')
        ->and($types)->toContain('document')
        ->and($types)->toContain('changeset')
        ->and($types)->toContain('time-entry');

    expect($wikiPage->currentVersion)->not->toBeNull();
    expect($topic->isTopic())->toBeTrue();
});

test('unchecking a type filters it out of the feed', function () {
    $project = Project::factory()->create();
    $user = activityMember($project, ['view_project', 'view_issues', 'view_news']);
    Issue::factory()->for($project)->create();
    News::factory()->for($project)->create();

    $component = Livewire::actingAs($user)->test('activity.index', ['project' => $project]);
    $allTypes = $component->get('activeTypes');

    $component->set('activeTypes', array_values(array_diff($allTypes, ['issue'])))
        ->call('applyFilters');

    $types = $component->get('entries')->pluck('type');

    expect($types)->not->toContain('issue')
        ->and($types)->toContain('news');
});

test('entries are grouped by date', function () {
    $project = Project::factory()->create();
    $user = activityMember($project, ['view_project', 'view_issues']);
    Issue::factory()->for($project)->create(['created_at' => now()->subDay()]);
    Issue::factory()->for($project)->create(['created_at' => now()]);

    $component = Livewire::actingAs($user)->test('activity.index', ['project' => $project]);
    $grouped = $component->get('groupedEntries');

    expect($grouped)->toHaveCount(2);
});
