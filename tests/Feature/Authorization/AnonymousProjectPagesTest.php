<?php

use App\Enums\ProjectModuleKey;
use App\Enums\RoleBuiltin;
use App\Models\Board;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\Version;

/**
 * A1-41: with login_required off, a visitor who isn't logged in reaches a
 * public project's calendar, Gantt, search, activity, roadmap and Atom
 * feeds whenever the Anonymous role grants the permission (Redmine's
 * check_if_login_required + authorize), and nothing beyond it.
 *
 * @param  array<int, string>  $permissions
 * @return object{project: Project, bug: Tracker, feature: Tracker, issue: Issue, role: Role}
 */
function anonymousPagesScenario(array $permissions = ['view_project', 'view_issues', 'view_calendar', 'view_gantt', 'search_project'], bool $isPublic = true): object
{
    Setting::set('login_required', false);

    $role = Role::factory()->create(['builtin' => RoleBuiltin::Anonymous->value, 'issues_visibility' => 'all', 'permissions' => $permissions]);
    $project = Project::factory()->create(['is_public' => $isPublic]);
    $bug = Tracker::factory()->create(['name' => 'Bug', 'is_in_roadmap' => true]);
    $feature = Tracker::factory()->create(['name' => 'Feature']);
    $project->trackers()->attach([$bug->id, $feature->id]);
    Version::factory()->for($project)->create(['name' => 'Release ZQX', 'due_date' => now()->addMonth()->toDateString()]);

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $bug->id,
        'subject' => '見えてよい課題ZQX',
        'start_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);

    return (object) compact('project', 'bug', 'feature', 'issue', 'role');
}

/**
 * @return array<string, string>
 */
function anonymousProjectUrls(Project $project): array
{
    return [
        'calendar' => route('calendar.index', $project),
        'gantt' => route('gantt.index', $project),
        'search' => route('search.index', ['project' => $project, 'query' => 'ZQX']),
        'activity' => route('activity.index', $project),
        'roadmap' => route('versions.roadmap', $project),
        'issues.atom' => route('issues.atom', $project),
        'changes.atom' => route('issues.changes-atom', $project),
        'activity.atom' => route('activity.atom', $project),
    ];
}

test('with login_required off, a guest reaches every page and feed the Anonymous role allows', function () {
    $scenario = anonymousPagesScenario();

    foreach (anonymousProjectUrls($scenario->project) as $name => $url) {
        $response = $this->get($url);

        expect($response->status())->toBe(200, $name);
    }

    $this->get(route('calendar.index', $scenario->project))->assertSee('見えてよい課題ZQX');
    $this->get(route('gantt.index', $scenario->project))->assertSee('見えてよい課題ZQX');
    $this->get(route('search.index', ['project' => $scenario->project, 'query' => 'ZQX']))->assertSee('見えてよい課題ZQX');
    $this->get(route('activity.index', $scenario->project))->assertSee('見えてよい課題ZQX');
    $this->get(route('versions.roadmap', $scenario->project))->assertSee('Release ZQX');
    $this->get(route('issues.atom', $scenario->project))->assertSee('見えてよい課題ZQX');
    $this->get(route('activity.atom', $scenario->project))->assertSee('見えてよい課題ZQX');
});

test('with login_required on, a guest is sent to login from every one of them', function () {
    $scenario = anonymousPagesScenario();
    Setting::set('login_required', true);

    foreach (anonymousProjectUrls($scenario->project) as $name => $url) {
        expect($this->get($url)->isRedirect(route('login')))->toBeTrue($name);
    }

    expect($this->get(route('activity.global-atom'))->isRedirect(route('login')))->toBeTrue()
        ->and($this->get(route('issues.global-changes-atom'))->isRedirect(route('login')))->toBeTrue();
});

test('a guest is refused every one of them on a private project', function () {
    $scenario = anonymousPagesScenario(isPublic: false);

    foreach (anonymousProjectUrls($scenario->project) as $name => $url) {
        expect($this->get($url)->status())->toBe(403, $name);
    }
});

test('a guest is refused a page whose permission the Anonymous role lacks', function (string $missing, array $refused) {
    $all = ['view_project', 'view_issues', 'view_calendar', 'view_gantt', 'search_project'];
    $scenario = anonymousPagesScenario(array_values(array_diff($all, [$missing])));
    $urls = anonymousProjectUrls($scenario->project);

    foreach ($refused as $name) {
        expect($this->get($urls[$name])->status())->toBe(403, $name);
    }
})->with([
    'view_calendar' => ['view_calendar', ['calendar']],
    'view_gantt' => ['view_gantt', ['gantt']],
    'search_project' => ['search_project', ['search']],
    'view_issues' => ['view_issues', ['roadmap', 'issues.atom', 'changes.atom']],
]);

test('without view_issues a guest still gets the activity page and search but no issue in them', function () {
    $scenario = anonymousPagesScenario(['view_project', 'search_project']);

    $this->get(route('activity.index', $scenario->project))->assertOk()->assertDontSee('見えてよい課題ZQX');
    $this->get(route('activity.atom', $scenario->project))->assertOk()->assertDontSee('見えてよい課題ZQX');
    $this->get(route('search.index', ['project' => $scenario->project, 'query' => 'ZQX']))->assertOk()->assertDontSee('見えてよい課題ZQX');
});

test('a guest is refused the calendar and Gantt when their module is disabled', function () {
    $scenario = anonymousPagesScenario();
    $scenario->project->syncModules(collect(ProjectModuleKey::defaults())
        ->reject(fn (ProjectModuleKey $module) => in_array($module, [ProjectModuleKey::Calendar, ProjectModuleKey::Gantt], true))
        ->values()->all());

    $this->get(route('calendar.index', $scenario->project))->assertForbidden();
    $this->get(route('gantt.index', $scenario->project))->assertForbidden();
});

test('a guest never sees a private issue on these pages, nor jumps to it from the search box', function () {
    $scenario = anonymousPagesScenario();
    $private = Issue::factory()->for($scenario->project)->create([
        'tracker_id' => $scenario->bug->id,
        'subject' => '非公開の秘密課題ZQX',
        'is_private' => true,
        'start_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);

    foreach (anonymousProjectUrls($scenario->project) as $name => $url) {
        $this->get($url)->assertOk()->assertDontSee('非公開の秘密課題ZQX');
    }

    $this->get(route('search.index', ['project' => $scenario->project, 'query' => "#{$private->id}"]))
        ->assertOk()
        ->assertDontSee(route('issues.show', [$scenario->project, $private]));
    $this->get(route('search.index', ['project' => $scenario->project, 'query' => "#{$scenario->issue->id}"]))
        ->assertRedirect(route('issues.show', [$scenario->project, $scenario->issue]));
});

test('a guest sees only the trackers the Anonymous role may view issues of', function () {
    $scenario = anonymousPagesScenario();
    $scenario->role->setPermissionTrackers('view_issues', [$scenario->bug->id]);
    $scenario->role->save();
    Issue::factory()->for($scenario->project)->create([
        'tracker_id' => $scenario->feature->id,
        'subject' => '別トラッカーの課題ZQX',
        'start_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);

    foreach (anonymousProjectUrls($scenario->project) as $name => $url) {
        $this->get($url)->assertOk()->assertDontSee('別トラッカーの課題ZQX');
    }

    $this->get(route('calendar.index', $scenario->project))->assertSee('見えてよい課題ZQX');
});

test('a guest neither finds an issue by nor reads the changes of a custom field restricted to other roles', function () {
    $scenario = anonymousPagesScenario();
    $memberRole = Role::factory()->create(['permissions' => ['view_issues']]);
    $field = CustomField::factory()->create(['name' => '社内メモ', 'searchable' => true]);
    $field->trackers()->attach($scenario->bug);
    $field->roles()->attach($memberRole);
    $open = CustomField::factory()->create(['name' => '公開メモ', 'searchable' => true]);
    $open->trackers()->attach($scenario->bug);

    $hidden = Issue::factory()->for($scenario->project)->create(['tracker_id' => $scenario->bug->id, 'subject' => '件名だけの課題']);
    $hidden->setCustomFieldValues([$field->id => 'secret-token-qqq', $open->id => 'open-token-qqq']);

    $journal = Journal::create(['issue_id' => $hidden->id, 'user_id' => $hidden->author_id, 'notes' => null, 'private_notes' => false]);
    JournalDetail::create(['journal_id' => $journal->id, 'property' => 'cf', 'prop_key' => (string) $field->id, 'old_value' => 'old-secret-qqq', 'new_value' => 'secret-token-qqq']);

    $this->get(route('search.index', ['project' => $scenario->project, 'query' => 'secret-token-qqq']))
        ->assertOk()->assertDontSee('件名だけの課題');
    $this->get(route('search.index', ['project' => $scenario->project, 'query' => 'open-token-qqq']))
        ->assertOk()->assertSee('件名だけの課題');
    $this->get(route('issues.changes-atom', $scenario->project))
        ->assertOk()->assertDontSee('secret-token-qqq')->assertDontSee('社内メモ');
});

test('with login_required off, a guest reads the global and the news and forum feeds of public projects only', function () {
    $scenario = anonymousPagesScenario(['view_project', 'view_issues', 'view_news', 'view_messages']);
    $private = anonymousPagesScenarioPrivateIssue();
    $news = News::factory()->for($scenario->project)->create(['title' => '公開ニュースZQX']);
    $board = Board::factory()->for($scenario->project)->create();

    $this->get(route('activity.global-atom'))->assertOk()->assertSee('見えてよい課題ZQX')->assertDontSee('非公開プロジェクトの課題ZQX');
    $this->get(route('issues.global-changes-atom'))->assertOk()->assertDontSee('非公開プロジェクトの課題ZQX');
    $this->get(route('news.atom', $scenario->project))->assertOk()->assertSee('公開ニュースZQX');
    $this->get(route('boards.atom', [$scenario->project, $board]))->assertOk();
    $this->get(route('news.atom', $private->project))->assertForbidden();
});

function anonymousPagesScenarioPrivateIssue(): Issue
{
    $project = Project::factory()->private()->create();
    $issue = Issue::factory()->for($project)->create(['subject' => '非公開プロジェクトの課題ZQX']);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $issue->author_id, 'notes' => '非公開プロジェクトの課題ZQX のメモ', 'private_notes' => false]);

    return $issue;
}
