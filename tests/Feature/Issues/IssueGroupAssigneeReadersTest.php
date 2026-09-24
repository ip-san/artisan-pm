<?php

use App\Enums\FilterOperator;
use App\Enums\MailNotificationOption;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Watcher;
use App\Models\WorkflowTransition;
use App\Services\IncomingMailService;
use App\Services\IssueService;
use App\Services\WorkflowService;
use App\Support\Dashboard\Blocks\AssignedIssuesBlock;
use App\Support\Issues\AssigneeChoice;
use App\Support\Mail\NotificationRecipients;
use App\Support\Mail\ParsedIncomingMail;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use App\Support\Reports\IssueReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * A1-20b: every reader of "the assignee" treats a group assignee as all of
 * its current members, resolved when asked.
 *
 * @return object{project: Project, tracker: Tracker, status: IssueStatus, priority: Enumeration, role: Role, group: Group, member: User, other: User, issue: Issue}
 */
function groupReaderScenario(array $memberAttributes = [], array $otherAttributes = []): object
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $status = IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create(['is_default' => true]);
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues', 'edit_issues', 'import_issues'], 'assignable' => true]);

    $member = User::factory()->create($memberAttributes);
    $other = User::factory()->create($otherAttributes);

    foreach ([$member, $other] as $user) {
        Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    }

    $group = Group::factory()->create(['name' => 'Support team']);
    $group->users()->attach($member);
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id])->roles()->attach($role);

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
        'author_id' => User::factory()->create()->id,
        'assigned_to_group_id' => $group->id,
    ]);

    return (object) compact('project', 'tracker', 'status', 'priority', 'role', 'group', 'member', 'other', 'issue');
}

/**
 * @param  array<int, mixed>  $values
 * @return array<int, int>
 */
function groupReaderFilter(object $s, ?User $viewer, string $key, FilterOperator $operator, array $values = []): array
{
    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($s->project, $viewer));

    return $engine->applyFilters(Issue::query()->where('project_id', $s->project->id), [
        $key => ['operator' => $operator->value, 'values' => $values],
    ])->orderBy('id')->pluck('id')->all();
}

test('group members are notified as assignees, each by their own mail setting', function () {
    $s = groupReaderScenario(['mail_notification' => MailNotificationOption::OnlyAssigned], ['mail_notification' => MailNotificationOption::OnlyAssigned]);
    $myEvents = User::factory()->create(['mail_notification' => MailNotificationOption::OnlyMyEvents]);
    $optedOut = User::factory()->create(['mail_notification' => MailNotificationOption::None]);
    $s->group->users()->attach([$myEvents->id, $optedOut->id]);
    $actor = User::factory()->create();

    $recipients = NotificationRecipients::forIssue($s->issue, 'issue_added', $actor)->pluck('id');

    expect($recipients)->toContain($s->member->id)
        ->toContain($myEvents->id)
        ->not->toContain($optedOut->id)
        ->not->toContain($s->other->id)
        ->and(Watcher::query()->where('watchable_id', $s->issue->id)->whereIn('user_id', [$s->member->id, $myEvents->id])->exists())->toBeFalse();

    $s->group->users()->detach($s->member);

    expect(NotificationRecipients::forIssue($s->issue->fresh(), 'issue_added', $actor)->pluck('id'))->not->toContain($s->member->id);
});

test('my page and the user profile count issues assigned to the user\'s groups', function () {
    $s = groupReaderScenario();

    expect((new AssignedIssuesBlock)->rows($s->member)->pluck('url')->all())->toBe([route('issues.show', [$s->project, $s->issue])])
        ->and((new AssignedIssuesBlock)->rows($s->other))->toBeEmpty();

    expect(Livewire::actingAs($s->member)->test('users.show', ['user' => $s->member])->instance()->issueCounts['assigned']['total'])->toBe(1);
});

test('the assignee filter treats me as the user and their groups and accepts a group', function () {
    $s = groupReaderScenario();
    $userIssue = Issue::factory()->for($s->project)->create(['tracker_id' => $s->tracker->id, 'status_id' => $s->status->id, 'priority_id' => $s->priority->id, 'assigned_to_id' => $s->other->id]);
    $unassigned = Issue::factory()->for($s->project)->create(['tracker_id' => $s->tracker->id, 'status_id' => $s->status->id, 'priority_id' => $s->priority->id]);
    $groupValue = AssigneeChoice::forGroup($s->group);

    expect(groupReaderFilter($s, $s->member, 'assigned_to_id', FilterOperator::Equals, ['me']))->toBe([$s->issue->id])
        ->and(groupReaderFilter($s, $s->other, 'assigned_to_id', FilterOperator::Equals, ['me']))->toBe([$userIssue->id])
        ->and(groupReaderFilter($s, $s->other, 'assigned_to_id', FilterOperator::In, [$groupValue, (string) $s->other->id]))->toBe([$s->issue->id, $userIssue->id])
        ->and(groupReaderFilter($s, $s->other, 'assigned_to_id', FilterOperator::NotEquals, [$groupValue]))->toBe([$userIssue->id, $unassigned->id])
        ->and(groupReaderFilter($s, $s->other, 'assigned_to_id', FilterOperator::IsEmpty))->toBe([$unassigned->id])
        ->and(groupReaderFilter($s, $s->other, 'assigned_to_id', FilterOperator::IsNotEmpty))->toBe([$s->issue->id, $userIssue->id]);
});

test('groups are offered by the assignee filter only while group assignment is on', function () {
    $s = groupReaderScenario();
    $groupValue = AssigneeChoice::forGroup($s->group);

    expect(IssueFilterFieldRegistry::forProject($s->project, $s->member)->get('assigned_to_id')->options())->not->toHaveKey($groupValue)->toHaveKey('me');

    Setting::set('issue_group_assignment', true);

    expect(IssueFilterFieldRegistry::forProject($s->project, $s->member)->get('assigned_to_id')->options())->toHaveKey($groupValue);
});

test('the assignee group and role filters count a group assignee itself', function () {
    $s = groupReaderScenario();
    $userIssue = Issue::factory()->for($s->project)->create(['tracker_id' => $s->tracker->id, 'status_id' => $s->status->id, 'priority_id' => $s->priority->id, 'assigned_to_id' => $s->other->id]);
    $admin = User::factory()->create(['is_admin' => true]);

    expect(groupReaderFilter($s, $admin, 'member_of_group', FilterOperator::Equals, [$s->group->id]))->toBe([$s->issue->id])
        ->and(groupReaderFilter($s, $admin, 'member_of_group', FilterOperator::NotEquals, [$s->group->id]))->toBe([$userIssue->id])
        ->and(groupReaderFilter($s, $admin, 'assigned_to_role', FilterOperator::Equals, [$s->role->id]))->toBe([$s->issue->id, $userIssue->id])
        ->and(groupReaderFilter($s, $admin, 'assigned_to_role', FilterOperator::IsEmpty))->toBe([]);
});

test('the issue list sorts and groups by a group assignee\'s name', function () {
    $s = groupReaderScenario(['name' => 'Aaron'], ['name' => 'Zed']);
    Issue::factory()->for($s->project)->create(['tracker_id' => $s->tracker->id, 'status_id' => $s->status->id, 'priority_id' => $s->priority->id, 'assigned_to_id' => $s->other->id, 'subject' => 'Zed issue']);

    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($s->project, $s->member));
    $sorted = $engine->applySort(Issue::query()->where('project_id', $s->project->id), [['assigned_to_id', 'asc']])->pluck('id')->all();
    expect($sorted[0])->toBe($s->issue->id);

    Livewire::actingAs($s->member)->test('issues.index', ['project' => $s->project])
        ->set('sortKey', 'assigned_to_id')
        ->assertOk()
        ->assertSeeInOrder(['Support team', 'Zed issue']);

    Livewire::actingAs($s->member)->test('issues.index')
        ->set('sortKey', 'assigned_to_id')
        ->set('sortDirection', 'desc')
        ->assertOk()
        ->assertSeeInOrder(['Zed issue', 'Support team']);

    $totals = Livewire::actingAs($s->member)->test('issues.index', ['project' => $s->project])
        ->set('groupBy', 'assigned_to_id')
        ->assertSee('Support team')
        ->instance()->groupTotals;

    expect($totals['Support team']['count'])->toBe(1)
        ->and($totals['Zed']['count'])->toBe(1);
});

test('the api treats assigned_to_id=me as the caller and their groups', function () {
    $s = groupReaderScenario();

    Passport::actingAs($s->member);

    $this->getJson("/api/v1/projects/{$s->project->id}/issues?assigned_to_id=me")
        ->assertOk()->assertJsonPath('data.0.id', $s->issue->id);

    $this->getJson("/api/v1/projects/{$s->project->id}/issues?f[]=assigned_to_id&op[assigned_to_id]==&v[assigned_to_id][]=me")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $s->issue->id);

    Passport::actingAs($s->other);

    $this->getJson("/api/v1/projects/{$s->project->id}/issues?assigned_to_id=me")
        ->assertOk()->assertJsonCount(0, 'data');
});

test('assignee-only workflow transitions apply to members of the assigned group', function () {
    $s = groupReaderScenario();
    $next = IssueStatus::factory()->create();
    WorkflowTransition::query()->create([
        'tracker_id' => $s->tracker->id, 'role_id' => $s->role->id,
        'old_status_id' => $s->status->id, 'new_status_id' => $next->id,
        'author' => false, 'assignee' => true,
    ]);

    $workflow = app(WorkflowService::class);

    expect($workflow->allowedTransitions($s->issue, $s->member)->pluck('id'))->toContain($next->id)
        ->and($workflow->allowedTransitions($s->issue, $s->other)->pluck('id'))->not->toContain($next->id);
});

test('a copy keeps a group assignee only where the group is a member, a move always keeps it', function () {
    $s = groupReaderScenario();
    $withGroup = Project::factory()->create();
    $withGroup->trackers()->attach($s->tracker);
    Member::factory()->for($withGroup)->create(['user_id' => null, 'group_id' => $s->group->id])->roles()->attach($s->role);
    $withoutGroup = Project::factory()->create();
    $withoutGroup->trackers()->attach($s->tracker);
    $admin = User::factory()->create(['is_admin' => true]);
    $service = app(IssueService::class);

    expect($service->copy($s->issue, $withGroup, $s->tracker->id, $admin)->assigned_to_group_id)->toBe($s->group->id)
        ->and($service->copy($s->issue, $withoutGroup, $s->tracker->id, $admin)->assigned_to_group_id)->toBeNull()
        ->and($service->moveToProject($s->issue, $withoutGroup, $s->tracker->id, $admin)->assigned_to_group_id)->toBe($s->group->id);
});

test('an incoming mail and a csv import may name an assignable group', function () {
    Setting::set('mail_handler_allow_override', 'all');
    Storage::fake('local');
    Setting::set('issue_group_assignment', true);
    $s = groupReaderScenario(['email' => 'sender@example.com']);
    Setting::set('incoming_mail_default_project_id', $s->project->id);
    Setting::set('incoming_mail_default_tracker_id', $s->tracker->id);
    Setting::set('incoming_mail_default_status_id', $s->status->id);

    $mailed = app(IncomingMailService::class)->createIssueFromMail(new ParsedIncomingMail(
        subject: 'For the team', body: "Assigned to: support team\nPlease look.", fromEmail: 'sender@example.com',
    ));

    expect($mailed->assigned_to_group_id)->toBe($s->group->id)->and($mailed->assigned_to_id)->toBeNull();

    Livewire::actingAs($s->member)->test('issues.import', ['project' => $s->project])
        ->set('csvFile', UploadedFile::fake()->createWithContent('issues.csv', "subject,assigned_to\nImported row,Support team\n"))
        ->set('mapping.subject', 'subject')
        ->set('mapping.assigned_to', 'assigned_to')
        ->call('startImport');

    expect(Issue::query()->where('subject', 'Imported row')->value('assigned_to_group_id'))->toBe($s->group->id);
});

test('the issue report counts a group assignee on its own row', function () {
    $s = groupReaderScenario();
    $report = new IssueReport($s->project, User::factory()->create(['is_admin' => true]));
    $counts = $report->counts('assigned_to');
    $key = AssigneeChoice::forGroup($s->group);

    expect($counts[$key][$s->status->id])->toBe(1)
        ->and(collect($report->gridRows('assigned_to', $counts))->firstWhere('key', $key)['label'])->toBe('Support team');
});
