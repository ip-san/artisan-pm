<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;

function issueAtomMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function issueAtomStatus(bool $closed = false): IssueStatus
{
    return IssueStatus::factory()->create(['is_closed' => $closed]);
}

test('a member with view_issues can fetch the project issues atom feed, most recently updated first', function () {
    $project = Project::factory()->create();
    $user = issueAtomMember($project);
    $tracker = Tracker::factory()->create();
    $priority = Enumeration::factory()->create();
    $openStatus = issueAtomStatus();

    $older = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $openStatus->id, 'priority_id' => $priority->id,
        'subject' => 'Older issue', 'updated_at' => now()->subDay(),
    ]);
    $newer = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $openStatus->id, 'priority_id' => $priority->id,
        'subject' => 'Newer issue', 'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('issues.atom', $project));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/atom+xml');
    $response->assertSee('<feed', false);
    $response->assertSeeInOrder(["#{$newer->id} Newer issue", "#{$older->id} Older issue"], false);
});

test('the issue atom feed excludes closed issues by default', function () {
    $project = Project::factory()->create();
    $user = issueAtomMember($project);
    $tracker = Tracker::factory()->create();
    $priority = Enumeration::factory()->create();

    $open = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => issueAtomStatus()->id, 'priority_id' => $priority->id, 'subject' => 'Open issue',
    ]);
    $closed = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => issueAtomStatus(closed: true)->id, 'priority_id' => $priority->id, 'subject' => 'Closed issue',
    ]);

    $response = $this->actingAs($user)->get(route('issues.atom', $project));

    $response->assertSee("#{$open->id} Open issue", false);
    $response->assertDontSee("#{$closed->id} Closed issue", false);
});

test('the issue atom feed only shows issues the viewer is allowed to see', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => 'own']);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $tracker = Tracker::factory()->create();
    $priority = Enumeration::factory()->create();
    $status = issueAtomStatus();

    $mine = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => $priority->id,
        'subject' => 'My issue', 'author_id' => $user->id,
    ]);
    $notMine = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id, 'status_id' => $status->id, 'priority_id' => $priority->id,
        'subject' => 'Someone else\'s issue',
    ]);

    $response = $this->actingAs($user)->get(route('issues.atom', $project));

    $response->assertSee("#{$mine->id} My issue", false);
    $response->assertDontSee("#{$notMine->id}", false);
});

test('a member without view_issues cannot fetch the project issues atom feed', function () {
    $project = Project::factory()->create();
    $user = issueAtomMember($project, []);

    $this->actingAs($user)->get(route('issues.atom', $project))->assertForbidden();
});

function issueAtomIssue(Project $project, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => issueAtomStatus()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

test('the issue atom feed honours statusFilter', function () {
    $project = Project::factory()->create();
    $user = issueAtomMember($project);
    $open = issueAtomIssue($project, ['subject' => 'Still open']);
    $closed = issueAtomIssue($project, ['subject' => 'Already done', 'status_id' => issueAtomStatus(closed: true)->id]);

    $closedOnly = $this->actingAs($user)->get(route('issues.atom', [$project, 'statusFilter' => 'closed']));
    $all = $this->actingAs($user)->get(route('issues.atom', [$project, 'statusFilter' => 'all']));

    $closedOnly->assertSee('Already done', false)->assertDontSee('Still open', false);
    $all->assertSee('Already done', false)->assertSee('Still open', false);
});

test('the issue atom feed applies the list filters from the query string', function () {
    $project = Project::factory()->create();
    $user = issueAtomMember($project);
    $wanted = issueAtomIssue($project, ['subject' => 'Assigned to me', 'assigned_to_id' => $user->id]);
    $other = issueAtomIssue($project, ['subject' => 'Assigned elsewhere', 'assigned_to_id' => null]);

    $this->actingAs($user)
        ->get(route('issues.atom', [$project, 'activeFilterKeys' => ['assigned_to_id'], 'filterOperators' => ['assigned_to_id' => 'in'], 'filterValues' => ['assigned_to_id' => [(string) $user->id]]]))
        ->assertOk()
        ->assertSee('Assigned to me', false)
        ->assertDontSee('Assigned elsewhere', false);
});

test('the sort decides which issues make the feed cap and the entries are then shown newest first', function () {
    Setting::set('feeds_limit', 2);
    $project = Project::factory()->create();
    $user = issueAtomMember($project);
    issueAtomIssue($project, ['subject' => 'Alpha', 'updated_at' => now()->subDays(3)]);
    issueAtomIssue($project, ['subject' => 'Bravo', 'updated_at' => now()->subDays(1)]);
    issueAtomIssue($project, ['subject' => 'Charlie', 'updated_at' => now()->subDays(2)]);

    $response = $this->actingAs($user)->get(route('issues.atom', [$project, 'sortKey' => 'subject', 'sortDirection' => 'asc']));

    $response->assertSee('Alpha', false)->assertSee('Bravo', false)->assertDontSee('Charlie', false);
    $response->assertSeeInOrder(['Bravo', 'Alpha'], false);
});

test('malformed filter parameters are ignored instead of failing', function () {
    $project = Project::factory()->create();
    $user = issueAtomMember($project);
    issueAtomIssue($project, ['subject' => 'Survivor']);

    $this->actingAs($user)
        ->get(route('issues.atom', [$project]).'?activeFilterKeys=oops&filterOperators[status_id][]=x&filterValues=1&sortKey[]=a&sortDirection[]=b&statusFilter[]=z')
        ->assertOk();

    $this->actingAs($user)
        ->get(route('issues.atom', [$project, 'activeFilterKeys' => ['no_such_field'], 'filterOperators' => ['no_such_field' => '='], 'sortKey' => 'no_such_field']))
        ->assertOk()
        ->assertSee('Survivor', false);
});

test('filters cannot reveal issues the viewer is not allowed to see', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => 'default'])
    );
    $author = User::factory()->create();
    issueAtomIssue($project, ['subject' => 'Secret', 'is_private' => true, 'author_id' => $author->id]);

    $this->actingAs($user)
        ->get(route('issues.atom', [$project, 'statusFilter' => 'all']))
        ->assertOk()
        ->assertDontSee('Secret', false);
});

test('the atom link on the issue list carries the current filters and sort', function () {
    $project = Project::factory()->create();
    $user = issueAtomMember($project);

    $component = Livewire\Livewire::actingAs($user)
        ->withQueryParams([
            'statusFilter' => 'all',
            'activeFilterKeys' => ['status_id'],
            'filterOperators' => ['status_id' => 'in'],
            'filterValues' => ['status_id' => ['3']],
            'sortKey' => 'subject',
            'sortDirection' => 'desc',
        ])
        ->test('issues.index', ['project' => $project]);

    $url = $component->instance()->atomUrl;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query)->toMatchArray([
        'statusFilter' => 'all',
        'activeFilterKeys' => ['status_id'],
        'filterOperators' => ['status_id' => 'in'],
        'filterValues' => ['status_id' => ['3']],
        'sortKey' => 'subject',
        'sortDirection' => 'desc',
    ]);
});
