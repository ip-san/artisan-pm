<?php

use App\Enums\EnumerationType;
use App\Enums\MailNotificationOption;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Services\RepositorySyncService;
use App\Services\SearchService;
use App\Support\Activity\Providers\IssueActivityProvider;
use App\Support\Activity\Providers\IssueJournalActivityProvider;
use App\Support\Activity\Providers\TimeEntryActivityProvider;
use App\Support\Dashboard\Blocks\AssignedIssuesBlock;
use App\Support\Dashboard\Blocks\CalendarBlock;
use App\Support\Dashboard\Blocks\ReportedIssuesBlock;
use App\Support\Dashboard\Blocks\UpdatedByMeBlock;
use App\Support\Dashboard\Blocks\WatchedIssuesBlock;
use App\Support\Issues\IssueSuggestions;
use App\Support\Mail\NotificationRecipients;
use App\Support\Markdown\WikiMarkdownRenderer;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use App\Support\Reports\IssueReport;
use Illuminate\Support\Facades\Process;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * A1-27b: a role whose view_issues is limited to some trackers must not see
 * issues of the other trackers through any read path. Every scenario gives
 * the user one role holding view_issues on the Bug tracker only; the
 * Feature issue (by someone else) must never show up.
 *
 * @param  array<int, string>  $permissions
 * @return object{project: Project, user: User, bug: Tracker, feature: Tracker, role: Role, bugIssue: Issue, featureIssue: Issue, status: IssueStatus, priority: Enumeration}
 */
function trackerScenario(array $permissions = []): object
{
    $project = Project::factory()->create(['is_public' => false]);
    $bug = Tracker::factory()->create(['name' => 'Bug']);
    $feature = Tracker::factory()->create(['name' => 'Feature']);
    $project->trackers()->attach([$bug->id, $feature->id]);
    $status = IssueStatus::factory()->create(['is_closed' => false]);
    $priority = Enumeration::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->limitedToTrackers('view_issues', [$bug->id])->create([
        'permissions' => ['view_issues', ...$permissions],
    ]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    $other = User::factory()->create();

    $make = fn (Tracker $tracker, string $subject) => Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
        'author_id' => $other->id,
        'subject' => $subject,
        'description' => "{$subject} description",
    ]);

    return (object) [
        'project' => $project,
        'user' => $user,
        'bug' => $bug,
        'feature' => $feature,
        'role' => $role,
        'status' => $status,
        'priority' => $priority,
        'bugIssue' => $make($bug, 'Visible bug zebra'),
        'featureIssue' => $make($feature, 'Hidden feature zebra'),
    ];
}

test('the issue policy only lets the role view issues of its trackers', function () {
    $s = trackerScenario();

    expect($s->user->can('view', $s->bugIssue))->toBeTrue()
        ->and($s->user->can('view', $s->featureIssue))->toBeFalse();

    Livewire::actingAs($s->user)->test('issues.show', ['project' => $s->project, 'issue' => $s->featureIssue])->assertForbidden();
    Livewire::actingAs($s->user)->test('issues.show', ['project' => $s->project, 'issue' => $s->bugIssue])->assertOk();
});

test('the project and global issue lists leave out the restricted tracker', function () {
    $s = trackerScenario();

    $projectIds = Livewire::actingAs($s->user)->test('issues.index', ['project' => $s->project])->set('statusFilter', 'all')->instance()->issues->pluck('id');
    $globalIds = Livewire::actingAs($s->user)->test('issues.global-index')->set('statusFilter', 'all')->instance()->issues->pluck('id');

    expect($projectIds->all())->toBe([$s->bugIssue->id])
        ->and($globalIds->all())->toBe([$s->bugIssue->id]);
});

test('the CSV export of the list leaves out the restricted tracker', function () {
    $s = trackerScenario();

    Livewire::actingAs($s->user)
        ->test('issues.index', ['project' => $s->project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject'])
        ->call('exportCsv')
        ->assertFileDownloaded("{$s->project->identifier}-issues.csv", "\xEF\xBB\xBF".csvRow(['題名']).csvRow(['Visible bug zebra']));
});

test('the REST issue list and issue show honour the tracker limit', function () {
    $s = trackerScenario();
    $key = $s->user->regenerateApiKey();

    $projectIds = collect($this->withHeaders(['X-Redmine-API-Key' => $key])->getJson("/api/v1/projects/{$s->project->id}/issues?status_id=*")->assertOk()->json('data'))->pluck('id')->all();
    $globalIds = collect($this->withHeaders(['X-Redmine-API-Key' => $key])->getJson('/api/v1/issues?status_id=*')->assertOk()->json('data'))->pluck('id')->all();

    expect($projectIds)->toBe([$s->bugIssue->id])
        ->and($globalIds)->toBe([$s->bugIssue->id]);

    $this->withHeaders(['X-Redmine-API-Key' => $key])->getJson("/api/v1/issues/{$s->featureIssue->id}")->assertForbidden();
});

test('both issue Atom feeds leave out the restricted tracker', function () {
    $s = trackerScenario();
    Journal::create(['issue_id' => $s->featureIssue->id, 'user_id' => $s->featureIssue->author_id, 'notes' => 'Secret feature note']);
    Journal::create(['issue_id' => $s->bugIssue->id, 'user_id' => $s->bugIssue->author_id, 'notes' => 'Public bug note']);

    $this->actingAs($s->user)->get(route('issues.atom', ['project' => $s->project, 'statusFilter' => 'all']))
        ->assertOk()->assertSee('Visible bug zebra')->assertDontSee('Hidden feature zebra');

    $this->actingAs($s->user)->get(route('issues.changes-atom', $s->project))
        ->assertOk()->assertSee('Public bug note')->assertDontSee('Secret feature note')->assertDontSee('Hidden feature zebra');
});

test('search, in a project, across projects and over the API, leaves out the restricted tracker', function () {
    $s = trackerScenario();
    $search = app(SearchService::class);

    $titles = fn ($results) => $results->pluck('title')->implode(' ');

    expect($titles($search->search($s->project, $s->user, 'zebra')))->toContain('Visible bug zebra')->not->toContain('Hidden feature zebra')
        ->and($titles($search->searchAcrossProjects(collect([$s->project]), $s->user, 'zebra')))->not->toContain('Hidden feature zebra');

    Passport::actingAs($s->user);
    $this->getJson('/api/v1/search?q=zebra')->assertOk()->assertDontSee('Hidden feature zebra');
});

test('the calendars and the Gantt chart leave out the restricted tracker', function () {
    $s = trackerScenario(['view_calendar', 'view_gantt']);
    $s->bugIssue->update(['start_date' => now()->toDateString(), 'due_date' => now()->addDay()->toDateString()]);
    $s->featureIssue->update(['start_date' => now()->toDateString(), 'due_date' => now()->addDay()->toDateString()]);

    Livewire::actingAs($s->user)->test('calendar.index', ['project' => $s->project])
        ->assertSee('Visible bug zebra')->assertDontSee('Hidden feature zebra');
    Livewire::actingAs($s->user)->test('calendar.global-index')
        ->assertSee('Visible bug zebra')->assertDontSee('Hidden feature zebra');

    $rows = Livewire::actingAs($s->user)->test('gantt.index', ['project' => $s->project])->instance()->rows;

    expect($rows->pluck('id')->all())->toBe([$s->bugIssue->id]);
});

test('a visible subtask of a hidden parent is drawn as a root on the Gantt chart', function () {
    $s = trackerScenario(['view_gantt']);
    $s->bugIssue->update(['parent_id' => $s->featureIssue->id]);

    $rows = Livewire::actingAs($s->user)->test('gantt.index', ['project' => $s->project])->instance()->rows;

    expect($rows->pluck('id')->all())->toBe([$s->bugIssue->id])
        ->and($rows->first()->depth)->toBe(0);
});

test('the issue report only counts the trackers the role may see', function () {
    $s = trackerScenario();

    $counts = (new IssueReport($s->project, $s->user))->counts('tracker');

    expect($counts)->toHaveKey($s->bug->id)->not->toHaveKey($s->feature->id);
});

test('the time report and time entry lists show a hidden issue by number only', function () {
    $s = trackerScenario(['view_time_entries']);
    TimeEntry::factory()->create(['project_id' => $s->project->id, 'issue_id' => $s->featureIssue->id, 'user_id' => $s->user->id, 'hours' => 2]);

    $report = Livewire::actingAs($s->user)->test('time-entries.report', ['project' => $s->project])->set('criteria', ['issue', 'tracker'])->instance()->report;
    $labels = collect($report->rows)->pluck('labels')->flatten()->all();

    expect($labels)->toContain("#{$s->featureIssue->id}")
        ->not->toContain("#{$s->featureIssue->id} Hidden feature zebra")
        ->not->toContain('Feature');

    Livewire::actingAs($s->user)->test('time-entries.index', ['project' => $s->project])
        ->assertSee("#{$s->featureIssue->id}")->assertDontSee('Hidden feature zebra');
});

test('the time entry form neither offers nor accepts a hidden issue', function () {
    $s = trackerScenario(['log_time', 'view_time_entries']);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'is_default' => true]);

    $component = Livewire::actingAs($s->user)->test('time-entries.form', ['project' => $s->project]);

    expect($component->instance()->projectIssues->pluck('id')->all())->toBe([$s->bugIssue->id]);

    $component->set('issue_id', $s->featureIssue->id)
        ->set('activity_id', $activity->id)
        ->set('hours', '1')
        ->call('save')
        ->assertHasErrors('issue_id');

    Livewire::actingAs($s->user)->withQueryParams(['issue_id' => $s->featureIssue->id])
        ->test('time-entries.form', ['project' => $s->project])
        ->assertSet('issue_id', null)
        ->assertDontSee('Hidden feature zebra');
});

test('the REST time entry API refuses a hidden issue', function () {
    $s = trackerScenario(['log_time', 'view_time_entries']);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'is_default' => true]);

    $this->withHeaders(['X-Redmine-API-Key' => $s->user->regenerateApiKey()])
        ->postJson("/api/v1/projects/{$s->project->id}/time_entries", ['issue_id' => $s->featureIssue->id, 'hours' => 1, 'activity_id' => $activity->id])
        ->assertUnprocessable();
});

test('the issue page hides relations, subtasks and a parent of the restricted tracker', function () {
    $s = trackerScenario();
    $child = Issue::factory()->for($s->project)->create(['tracker_id' => $s->feature->id, 'status_id' => $s->status->id, 'priority_id' => $s->priority->id, 'subject' => 'Hidden child zebra', 'parent_id' => $s->bugIssue->id]);
    IssueRelation::create(['issue_from_id' => $s->bugIssue->id, 'issue_to_id' => $s->featureIssue->id, 'relation_type' => 'relates']);

    Livewire::actingAs($s->user)->test('issues.show', ['project' => $s->project, 'issue' => $s->bugIssue])
        ->assertOk()
        ->assertDontSee('Hidden feature zebra')
        ->assertDontSee('Hidden child zebra');

    $s->bugIssue->update(['parent_id' => $s->featureIssue->id]);
    $child->delete();

    Livewire::actingAs($s->user)->test('issues.show', ['project' => $s->project, 'issue' => $s->bugIssue->fresh()])
        ->assertOk()
        ->assertDontSee('Hidden feature zebra');

    $this->actingAs($s->user)->get(route('issues.pdf', [$s->project, $s->bugIssue]))->assertOk();
});

test('relation and subtask filters do not match through a hidden issue', function () {
    $s = trackerScenario();
    IssueRelation::create(['issue_from_id' => $s->bugIssue->id, 'issue_to_id' => $s->featureIssue->id, 'relation_type' => 'blocks']);
    $s->featureIssue->update(['parent_id' => $s->bugIssue->id]);
    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($s->project, $s->user));

    $ids = fn (string $key, string $operator, array $values = []) => $engine
        ->applyFilters(Issue::query()->visibleTo($s->user, $s->project), [$key => ['operator' => $operator, 'values' => $values]])
        ->pluck('issues.id')->all();

    expect($ids('blocks', 'not_empty'))->toBe([])
        ->and($ids('blocks', '=', [(string) $s->featureIssue->id]))->toBe([])
        ->and($ids('child_id', 'not_empty'))->toBe([])
        ->and($ids('child_id', '=', [(string) $s->featureIssue->id]))->toBe([])
        ->and($ids('blocks', 'empty'))->toBe([$s->bugIssue->id]);
});

test('the roadmap and version pages only count the visible issues', function () {
    $s = trackerScenario(['view_issues']);
    $version = Version::factory()->for($s->project)->create();
    $s->bugIssue->update(['fixed_version_id' => $version->id, 'estimated_hours' => 2]);
    $s->featureIssue->update(['fixed_version_id' => $version->id, 'estimated_hours' => 5]);

    $seen = $version->asSeenBy($s->user);

    expect($seen->issueCounts())->toBe(['open' => 1, 'closed' => 0])
        ->and($seen->estimatedHours())->toBe(2.0)
        ->and($version->issueCounts())->toBe(['open' => 2, 'closed' => 0]);

    Livewire::actingAs($s->user)->test('versions.roadmap', ['project' => $s->project])->assertSee(__(':count件の課題', ['count' => 1]));
});

test('the my page issue blocks leave out the restricted tracker', function () {
    $s = trackerScenario();
    $s->featureIssue->update(['assigned_to_id' => $s->user->id, 'author_id' => $s->user->id, 'start_date' => now()->toDateString()]);
    $s->bugIssue->update(['start_date' => now()->toDateString()]);
    $s->featureIssue->watchers()->create(['user_id' => $s->user->id]);
    Journal::create(['issue_id' => $s->featureIssue->id, 'user_id' => $s->user->id, 'notes' => 'mine']);

    foreach ([new AssignedIssuesBlock, new ReportedIssuesBlock, new WatchedIssuesBlock, new CalendarBlock, new UpdatedByMeBlock] as $block) {
        expect($block->rows($s->user)->pluck('title')->implode(' '))->not->toContain('Hidden feature zebra');
    }
});

test('the activity leaves out the restricted tracker, its journals and names only visible issues on time entries', function () {
    $s = trackerScenario(['view_time_entries']);
    Journal::create(['issue_id' => $s->featureIssue->id, 'user_id' => $s->featureIssue->author_id, 'notes' => 'note']);
    TimeEntry::factory()->create(['project_id' => $s->project->id, 'issue_id' => $s->featureIssue->id, 'user_id' => $s->user->id, 'hours' => 1, 'spent_on' => now()->toDateString()]);
    [$from, $to] = [now()->subDay(), now()->addDay()];

    foreach ([IssueActivityProvider::class, IssueJournalActivityProvider::class, TimeEntryActivityProvider::class] as $provider) {
        expect(app($provider)->entries($s->project, $s->user, $from, $to)->pluck('title')->implode(' '))->not->toContain('Hidden feature zebra');
    }

    expect(app(IssueActivityProvider::class)->entries($s->project, $s->user, $from, $to)->pluck('title')->implode(' '))->toContain('Visible bug zebra');
});

test('mail notifications skip members who may not see the tracker', function () {
    $s = trackerScenario();
    $s->user->update(['mail_notification' => MailNotificationOption::All]);

    $recipients = NotificationRecipients::forIssue($s->featureIssue, 'issue_added', User::factory()->create());

    expect($recipients->pluck('id'))->not->toContain($s->user->id);
    expect(NotificationRecipients::forIssue($s->bugIssue, 'issue_added', User::factory()->create())->pluck('id'))->toContain($s->user->id);
});

test('parent and relation pickers only suggest visible issues', function () {
    $s = trackerScenario();

    expect(IssueSuggestions::search($s->user, 'zebra', $s->project)->pluck('id')->all())->toBe([$s->bugIssue->id]);
});

test('an issue link in wiki text is not rendered for a hidden issue', function () {
    $s = trackerScenario();
    $this->actingAs($s->user);

    $html = app(WikiMarkdownRenderer::class)->render("See #{$s->featureIssue->id} and #{$s->bugIssue->id}", $s->project);

    expect($html)->toContain(route('issues.show', [$s->project, $s->bugIssue]))
        ->not->toContain(route('issues.show', [$s->project, $s->featureIssue]));
});

test('a changeset lists only the related issues the viewer may see', function () {
    $s = trackerScenario(['browse_repository', 'view_changesets']);
    $path = sys_get_temp_dir().'/scm-tracker-'.uniqid();
    mkdir($path);
    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();
    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'dev@example.com']);
    $run(['git', 'config', 'user.name', 'Dev']);
    file_put_contents("{$path}/a.txt", "a\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Change']);
    $repository = Repository::factory()->for($s->project)->create(['path' => $path]);
    app(RepositorySyncService::class)->sync($repository);
    $changeset = $repository->changesets()->with('repository.project')->orderBy('id')->get()->last();
    $changeset->issues()->sync([$s->bugIssue->id, $s->featureIssue->id]);

    Livewire::actingAs($s->user)->test('repository.show', ['project' => $s->project, 'changeset' => $changeset])
        ->assertSee('Visible bug zebra')->assertDontSee('Hidden feature zebra');

    Process::path(sys_get_temp_dir())->run(['rm', '-rf', $path]);
});

test('the user page counts only visible issues', function () {
    $s = trackerScenario(['view_project']);
    $author = User::find($s->featureIssue->author_id);

    $counts = Livewire::actingAs($s->user)->test('users.show', ['user' => $author])->instance()->issueCounts;

    expect($counts['reported']['total'])->toBe(1);
});

test('several roles are unioned per role, like Redmine: tier and trackers go together', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $bug = Tracker::factory()->create();
    $feature = Tracker::factory()->create();
    $user = User::factory()->create();
    $other = User::factory()->create();

    // Own issues of every tracker, directly; every Feature issue through a group.
    $own = Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => 'own']);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($own);
    $group = Group::factory()->create();
    $group->users()->attach($user);
    $featureOnly = Role::factory()->limitedToTrackers('view_issues', [$feature->id])->create(['permissions' => ['view_issues'], 'issues_visibility' => 'all']);
    Member::factory()->for($project)->create(['group_id' => $group->id, 'user_id' => null])->roles()->attach($featureOnly);

    $othersFeature = Issue::factory()->for($project)->create(['tracker_id' => $feature->id, 'author_id' => $other->id]);
    $othersBug = Issue::factory()->for($project)->create(['tracker_id' => $bug->id, 'author_id' => $other->id]);
    $myBug = Issue::factory()->for($project)->create(['tracker_id' => $bug->id, 'author_id' => $user->id]);

    $visible = Issue::query()->visibleTo($user, $project)->pluck('id')->sort()->values()->all();

    expect($visible)->toBe(collect([$othersFeature->id, $myBug->id])->sort()->values()->all())
        ->and($user->can('view', $othersFeature))->toBeTrue()
        ->and($user->can('view', $myBug))->toBeTrue()
        ->and($user->can('view', $othersBug))->toBeFalse()
        ->and(Issue::query()->visibleToAcrossProjects($user, collect([$project]))->pluck('id')->sort()->values()->all())->toBe($visible);
});

test('a role without view_issues does not widen what another role shows', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach(Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => 'own']));
    $member->roles()->attach(Role::factory()->create(['permissions' => ['view_project'], 'issues_visibility' => 'all']));

    $othersIssue = Issue::factory()->for($project)->create();

    expect($user->can('view', $othersIssue))->toBeFalse()
        ->and(Issue::query()->visibleTo($user, $project)->pluck('id')->all())->toBe([]);
});

test('an inherited role carries its tracker limit into the subproject', function () {
    $parent = Project::factory()->create(['is_public' => false]);
    $bug = Tracker::factory()->create();
    $feature = Tracker::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->limitedToTrackers('view_issues', [$bug->id])->create(['permissions' => ['view_issues']]);
    Member::factory()->for($parent)->for($user)->create()->roles()->attach($role);
    $child = Project::factory()->create(['is_public' => false, 'parent_id' => $parent->id, 'inherit_members' => true]);

    $bugIssue = Issue::factory()->for($child)->create(['tracker_id' => $bug->id]);
    $featureIssue = Issue::factory()->for($child)->create(['tracker_id' => $feature->id]);

    expect($user->can('view', $bugIssue))->toBeTrue()
        ->and($user->can('view', $featureIssue))->toBeFalse();
});

test('admins see every tracker', function () {
    $s = trackerScenario();
    $admin = User::factory()->admin()->create();

    expect($admin->can('view', $s->featureIssue))->toBeTrue()
        ->and(Issue::query()->visibleTo($admin, $s->project)->count())->toBe(2);
});

test('an issue cannot be given a parent the user may not see', function () {
    $s = trackerScenario(['edit_issues', 'manage_subtasks']);

    Livewire::actingAs($s->user)
        ->test('issues.form', ['project' => $s->project, 'issue' => $s->bugIssue])
        ->set('parent_id', $s->featureIssue->id)
        ->call('save')
        ->assertHasErrors(['parent_id']);

    expect($s->bugIssue->fresh()->parent_id)->toBeNull();
});
