<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function timeEntryAtomMember(Project $project, array $permissions = ['view_time_entries']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

function timeEntryAtomActivity(): Enumeration
{
    return Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
}

test('a member with view_time_entries can fetch the project time entries atom feed, most recently created first', function () {
    $project = Project::factory()->create();
    $user = timeEntryAtomMember($project);
    $activity = timeEntryAtomActivity();

    $older = TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'hours' => 1, 'created_at' => now()->subDay()]);
    $newer = TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'hours' => 2, 'created_at' => now()]);

    $response = $this->actingAs($user)->get(route('time-entries.atom', $project));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/atom+xml');
    $response->assertSee('<feed', false);
    $response->assertSeeInOrder(['2:00', '1:00'], false);
});

test('a member without view_time_entries cannot fetch the project time entries atom feed', function () {
    $project = Project::factory()->create();
    $user = timeEntryAtomMember($project, []);

    $this->actingAs($user)->get(route('time-entries.atom', $project))->assertForbidden();
});

test('the time entries atom feed applies the list filters from the query string', function () {
    // The feed's entry title (":hours時間 (issue-or-project)") never carries
    // the comment text, so the filter is proven by the hours it lets
    // through, not by seeing the comment itself.
    $project = Project::factory()->create();
    $user = timeEntryAtomMember($project);
    $activity = timeEntryAtomActivity();
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'hours' => 3, 'comments' => 'Wanted entry']);
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'hours' => 4, 'comments' => 'Other entry']);

    $this->actingAs($user)
        ->get(route('time-entries.atom', [$project, 'activeFilterKeys' => ['comments'], 'filterOperators' => ['comments' => '~'], 'filterValues' => ['comments' => ['Wanted']]]))
        ->assertOk()
        ->assertSee('3:00', false)
        ->assertDontSee('4:00', false);
});

test('the atom link on the time entries list carries the current filters', function () {
    $project = Project::factory()->create();
    $user = timeEntryAtomMember($project);

    $component = Livewire::actingAs($user)
        ->withQueryParams([
            'activeFilterKeys' => ['comments'],
            'filterOperators' => ['comments' => '~'],
            'filterValues' => ['comments' => ['abc']],
        ])
        ->test('time-entries.index', ['project' => $project]);

    $url = $component->instance()->atomUrl;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query)->toMatchArray([
        'activeFilterKeys' => ['comments'],
        'filterOperators' => ['comments' => '~'],
        'filterValues' => ['comments' => ['abc']],
    ]);

    Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])->assertSee(route('time-entries.atom', $project), false);
});

test('the cross-project time entries feed lists only entries the reader may see, across projects', function () {
    $mine = Project::factory()->create(['name' => 'Mine']);
    $other = Project::factory()->private()->create(['name' => 'Other']);
    $user = timeEntryAtomMember($mine);
    $activity = timeEntryAtomActivity();

    TimeEntry::factory()->for($mine)->for($user)->create(['activity_id' => $activity->id, 'hours' => 1]);
    TimeEntry::factory()->for($other)->create(['activity_id' => $activity->id, 'hours' => 1]);

    $response = $this->actingAs($user)->get(route('time-entries.global-atom'))->assertOk();

    $response->assertSee('(Mine)', false)->assertDontSee('(Other)', false);
});

test('the atom link on the global time entries list points at the global feed', function () {
    $project = Project::factory()->create();
    $user = timeEntryAtomMember($project);

    Livewire::actingAs($user)->test('time-entries.global-index')->assertSee(route('time-entries.global-atom'), false);
});

test('the time entries atom feed links to the related issue when the viewer may see it', function () {
    $project = Project::factory()->create();
    $user = timeEntryAtomMember($project, ['view_time_entries', 'view_issues']);
    $activity = timeEntryAtomActivity();
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Linked issue',
    ]);
    TimeEntry::factory()->for($project)->for($user)->for($issue)->create(['activity_id' => $activity->id, 'hours' => 1]);

    $response = $this->actingAs($user)->get(route('time-entries.atom', $project))->assertOk();

    $response->assertSee(route('issues.show', [$project, $issue]), false);
});

test('the cross-project time entries feed needs a login or atom key when login is required', function () {
    Setting::set('login_required', true);

    $this->get(route('time-entries.global-atom'))->assertRedirect(route('login'));
});
