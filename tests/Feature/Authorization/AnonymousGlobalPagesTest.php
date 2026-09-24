<?php

use App\Enums\ProjectModuleKey;
use App\Enums\RoleBuiltin;
use App\Models\Board;
use App\Models\CustomField;
use App\Models\Document;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use Livewire\Volt\Volt;

/**
 * A1-44: with login_required off, a visitor who isn't logged in reaches the
 * project list and overview, the global issue list/calendar/Gantt/search/
 * activity and a public project's boards, news, documents and files
 * whenever the Anonymous role grants the permission (Redmine's
 * check_if_login_required + authorize) — and nothing beyond it.
 *
 * @param  array<int, string>  $permissions
 * @return object{project: Project, private: Project, bug: Tracker, feature: Tracker, issue: Issue, role: Role, board: Board, topic: Message, news: News, document: Document}
 */
function anonymousGlobalScenario(array $permissions = ['view_project', 'view_issues', 'view_calendar', 'view_gantt', 'search_project', 'view_messages', 'view_news', 'view_documents', 'view_files']): object
{
    Setting::set('login_required', false);

    $role = Role::factory()->create(['builtin' => RoleBuiltin::Anonymous->value, 'issues_visibility' => 'all', 'permissions' => $permissions]);
    $project = Project::factory()->create(['name' => '公開プロジェクトZQX', 'is_public' => true]);
    $private = Project::factory()->private()->create(['name' => '非公開プロジェクトZQX']);
    $bug = Tracker::factory()->create(['name' => 'Bug']);
    $feature = Tracker::factory()->create(['name' => 'Feature']);
    $project->trackers()->attach([$bug->id, $feature->id]);
    $private->trackers()->attach([$bug->id]);

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $bug->id,
        'subject' => '見えてよい課題ZQX',
        'start_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);
    Issue::factory()->for($private)->create([
        'tracker_id' => $bug->id,
        'subject' => '非公開プロジェクトの課題ZQX',
        'start_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);

    $board = Board::factory()->for($project)->create(['name' => '公開フォーラムZQX']);
    $topic = Message::factory()->for($board)->create(['subject' => '公開トピックZQX']);
    $news = News::factory()->for($project)->create(['title' => '公開ニュースZQX']);
    $document = Document::factory()->for($project)->create(['title' => '公開文書ZQX']);

    return (object) compact('project', 'private', 'bug', 'feature', 'issue', 'role', 'board', 'topic', 'news', 'document');
}

/**
 * @return array<string, array{0: string, 1: string|null}> page => [url, text it shows]
 */
function anonymousGlobalUrls(object $scenario): array
{
    $project = $scenario->project;

    return [
        'projects' => [route('projects.index'), '公開プロジェクトZQX'],
        'overview' => [route('projects.show', $project), '公開プロジェクトZQX'],
        'issues' => [route('issues.global-index'), '見えてよい課題ZQX'],
        'calendar' => [route('calendar.global-index'), '見えてよい課題ZQX'],
        'gantt' => [route('gantt.global-index'), '見えてよい課題ZQX'],
        'search' => [route('search.global-index', ['query' => 'ZQX']), '見えてよい課題ZQX'],
        'activity' => [route('activity.global-index'), '見えてよい課題ZQX'],
        'global news' => [route('news.global-index'), '公開ニュースZQX'],
        'boards' => [route('boards.index', $project), '公開フォーラムZQX'],
        'board' => [route('boards.show', [$project, $scenario->board]), '公開トピックZQX'],
        'topic' => [route('messages.show', [$project, $scenario->board, $scenario->topic]), '公開トピックZQX'],
        'news' => [route('news.index', $project), '公開ニュースZQX'],
        'news item' => [route('news.show', [$project, $scenario->news]), '公開ニュースZQX'],
        'documents' => [route('documents.index', $project), '公開文書ZQX'],
        'document' => [route('documents.show', [$project, $scenario->document]), '公開文書ZQX'],
        'files' => [route('files.index', $project), null],
    ];
}

test('with login_required off, a guest reaches every page the Anonymous role allows', function () {
    $scenario = anonymousGlobalScenario();

    foreach (anonymousGlobalUrls($scenario) as $name => [$url, $text]) {
        $response = $this->get($url);

        expect($response->status())->toBe(200, $name);

        if ($text !== null) {
            $response->assertSee($text);
        }

        $response->assertDontSee('非公開プロジェクト');
    }
});

test('with login_required on, a guest is sent to login from every one of them', function () {
    $scenario = anonymousGlobalScenario();
    Setting::set('login_required', true);

    foreach (anonymousGlobalUrls($scenario) as $name => [$url]) {
        expect($this->get($url)->isRedirect(route('login')))->toBeTrue($name);
    }
});

test('a guest is refused the pages of a private project', function () {
    $scenario = anonymousGlobalScenario();
    $board = Board::factory()->for($scenario->private)->create();
    $news = News::factory()->for($scenario->private)->create();
    $document = Document::factory()->for($scenario->private)->create();

    foreach ([
        route('projects.show', $scenario->private),
        route('boards.index', $scenario->private),
        route('boards.show', [$scenario->private, $board]),
        route('news.index', $scenario->private),
        route('news.show', [$scenario->private, $news]),
        route('documents.index', $scenario->private),
        route('documents.show', [$scenario->private, $document]),
        route('files.index', $scenario->private),
    ] as $url) {
        expect($this->get($url)->status())->toBe(403, $url);
    }
});

test('a guest is refused a page whose permission the Anonymous role lacks', function (string $missing, array $refused) {
    $all = ['view_project', 'view_issues', 'view_calendar', 'view_gantt', 'search_project', 'view_messages', 'view_news', 'view_documents', 'view_files'];
    $scenario = anonymousGlobalScenario(array_values(array_diff($all, [$missing])));
    $urls = anonymousGlobalUrls($scenario);

    foreach ($refused as $name) {
        expect($this->get($urls[$name][0])->status())->toBe(403, $name);
    }
})->with([
    'view_messages' => ['view_messages', ['boards', 'board', 'topic']],
    'view_news' => ['view_news', ['news', 'news item']],
    'view_documents' => ['view_documents', ['documents', 'document']],
    'view_files' => ['view_files', ['files']],
]);

test('without the permission the global pages show nothing of that project', function () {
    $scenario = anonymousGlobalScenario(['view_project']);

    foreach (['issues', 'calendar', 'gantt', 'search', 'activity'] as $name) {
        $this->get(anonymousGlobalUrls($scenario)[$name][0])->assertDontSee('見えてよい課題ZQX');
    }
});

test('the global news list shows a guest only the news of public projects whose Anonymous role may view news (A1-52)', function () {
    $scenario = anonymousGlobalScenario();
    News::factory()->for($scenario->private)->create(['title' => '非公開のニュースZQX']);
    $noModule = Project::factory()->create(['is_public' => true]);
    $noModule->syncModules([ProjectModuleKey::IssueTracking]);
    News::factory()->for($noModule)->create(['title' => 'モジュール無効のニュースZQX']);

    $this->get(route('news.global-index'))->assertOk()
        ->assertSee('公開ニュースZQX')
        ->assertDontSee('非公開のニュースZQX')
        ->assertDontSee('モジュール無効のニュースZQX');
});

test('without view_news the global news list shows a guest nothing', function () {
    anonymousGlobalScenario(['view_project']);

    $this->get(route('news.global-index'))->assertOk()->assertDontSee('公開ニュースZQX');
});

test('a guest is refused the pages of a disabled module and the global pages skip it', function () {
    $scenario = anonymousGlobalScenario();
    $scenario->project->syncModules(collect(ProjectModuleKey::defaults())
        ->reject(fn (ProjectModuleKey $module) => in_array($module, [ProjectModuleKey::IssueTracking, ProjectModuleKey::Boards, ProjectModuleKey::News, ProjectModuleKey::Documents, ProjectModuleKey::Files], true))
        ->values()->all());
    $urls = anonymousGlobalUrls($scenario);

    foreach (['boards', 'board', 'topic', 'news', 'news item', 'documents', 'document', 'files'] as $name) {
        expect($this->get($urls[$name][0])->status())->toBe(403, $name);
    }

    foreach (['issues', 'calendar', 'gantt', 'search', 'activity'] as $name) {
        $this->get($urls[$name][0])->assertDontSee('見えてよい課題ZQX');
    }
});

test('a guest never sees a private issue, an issue of a tracker the role may not view, or a restricted custom field', function () {
    $scenario = anonymousGlobalScenario();
    $scenario->role->setPermissionTrackers('view_issues', [$scenario->bug->id]);
    $scenario->role->save();
    $today = ['start_date' => now()->toDateString(), 'due_date' => now()->toDateString()];
    Issue::factory()->for($scenario->project)->create(['tracker_id' => $scenario->bug->id, 'subject' => '非公開の秘密課題ZQX', 'is_private' => true, ...$today]);
    Issue::factory()->for($scenario->project)->create(['tracker_id' => $scenario->feature->id, 'subject' => '別トラッカーの課題ZQX', ...$today]);
    $field = CustomField::factory()->create(['name' => '社内メモ', 'searchable' => true]);
    $field->trackers()->attach($scenario->bug);
    $field->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    $scenario->issue->setCustomFieldValues([$field->id => 'secret-token-qqq']);

    foreach (['issues', 'calendar', 'gantt', 'search', 'activity'] as $name) {
        $this->get(anonymousGlobalUrls($scenario)[$name][0])->assertOk()
            ->assertDontSee('非公開の秘密課題ZQX')
            ->assertDontSee('別トラッカーの課題ZQX');
    }

    $this->get(route('issues.global-index', ['c' => ['subject', "cf_{$field->id}"]]))->assertOk()->assertDontSee('社内メモ');
    $this->get(route('search.global-index', ['query' => 'secret-token-qqq']))->assertOk()->assertDontSee('見えてよい課題ZQX');
});

test('the header offers a guest the pages open to them only while login_required is off', function () {
    anonymousGlobalScenario();

    $this->get(route('projects.index'))
        ->assertSee(route('issues.global-index'))
        ->assertSee(route('activity.global-index'))
        ->assertSee(route('search.global-index'))
        ->assertSee(route('news.global-index'))
        ->assertDontSee(route('my-page.index'))
        ->assertDontSee(route('time-entries.global-index'));
});

test('the overview lists only the subprojects the guest may see', function () {
    $scenario = anonymousGlobalScenario();
    Project::factory()->create(['name' => '公開サブプロジェクトZQX', 'is_public' => true, 'parent_id' => $scenario->project->id]);
    Project::factory()->private()->create(['name' => '非公開サブプロジェクトZQX', 'parent_id' => $scenario->project->id]);

    $this->get(route('projects.show', $scenario->project))->assertOk()
        ->assertSee('公開サブプロジェクトZQX')
        ->assertDontSee('非公開サブプロジェクトZQX')
        ->assertDontSee('ブックマーク');
});

test('with login_required on the guest header offers none of those pages', function () {
    Setting::set('login_required', true);

    $this->get(route('login'))->assertOk()->assertDontSee(route('issues.global-index'));
});

test('the project list offers a guest no bookmarks and refuses a bookmark toggle', function () {
    $scenario = anonymousGlobalScenario();

    $this->get(route('projects.index'))->assertOk()
        ->assertDontSee('toggleBookmark')
        ->assertDontSee('ブックマークしたプロジェクトのみ表示');
    $this->get(route('projects.index', ['display_type' => 'list']))->assertOk()->assertDontSee('toggleBookmark');

    Volt::test('projects.index')
        ->call('toggleBookmark', $scenario->project->id)
        ->assertForbidden();
    Volt::test('projects.show', ['project' => $scenario->project])
        ->call('toggleBookmark')
        ->assertForbidden();
});

test('a guest cannot write on the opened pages even when the Anonymous role grants it', function () {
    $scenario = anonymousGlobalScenario(['view_project', 'view_messages', 'add_messages', 'view_news', 'comment_news', 'view_files', 'manage_files', 'view_documents', 'delete_documents']);

    $this->get(route('files.index', $scenario->project))->assertOk()->assertDontSee('wire:submit="upload"', false);

    Volt::test('messages.show', ['project' => $scenario->project, 'board' => $scenario->board, 'message' => $scenario->topic])
        ->set('replyContent', '匿名の返信')
        ->call('addReply')
        ->assertForbidden();
    Volt::test('news.show', ['project' => $scenario->project, 'news' => $scenario->news])
        ->set('commentContent', '匿名のコメント')
        ->call('addComment')
        ->assertForbidden();
    Volt::test('documents.show', ['project' => $scenario->project, 'document' => $scenario->document])
        ->call('delete')
        ->assertForbidden();

    expect(Message::query()->where('content', '匿名の返信')->exists())->toBeFalse()
        ->and(Document::query()->whereKey($scenario->document->id)->exists())->toBeTrue();
});

test('a guest sees no private note in the global activity or search', function () {
    $scenario = anonymousGlobalScenario();
    Journal::create(['issue_id' => $scenario->issue->id, 'user_id' => $scenario->issue->author_id, 'notes' => '非公開メモZQX', 'private_notes' => true]);

    $this->get(route('activity.global-index'))->assertOk()->assertDontSee('非公開メモZQX');
    $this->get(route('search.global-index', ['query' => '非公開メモZQX']))->assertOk()->assertDontSee('見えてよい課題ZQX');
});

test('the search scopes of a signed-in user do not break the guest search', function () {
    anonymousGlobalScenario();

    $this->get(route('search.global-index', ['query' => 'ZQX', 'myProjectsOnly' => 1, 'bookmarkedOnly' => 1]))->assertOk()
        ->assertDontSee('非公開プロジェクトの課題ZQX');
});
