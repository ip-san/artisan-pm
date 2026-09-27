<?php

use App\Models\Board;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Message;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use Database\Seeders\DemoDataSeeder;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;

/**
 * End-to-end workflows as an ordinary project member, on a slice of the demo data set. Every
 * step is checked with the page audit; findings recorded in baselines/scenarios.json are
 * tolerated, anything new fails (UPDATE_PAGE_AUDIT_BASELINE=1 refreshes them).
 */
const SCENARIO_BASELINE = __DIR__.'/baselines/scenarios.json';

/**
 * @return array{0: Project, 1: User}
 */
function scenarioDeveloper(): array
{
    test()->seed();
    putenv('DEMO_DATA_SCALE=0.1');
    test()->seed(DemoDataSeeder::class);
    putenv('DEMO_DATA_SCALE');

    $project = Project::query()->where('identifier', 'ec-renewal')->firstOrFail();
    $developer = User::factory()->create(['name' => '開発 太郎', 'language' => 'ja']);
    $developerRole = Role::query()->whereNull('builtin')->orderBy('position')->skip(1)->firstOrFail();
    $project->members()->create(['user_id' => $developer->id])->roles()->attach($developerRole);
    test()->actingAs($developer);

    return [$project, $developer];
}

/**
 * @param  array<string, list<string>>  $findings
 */
function scenarioStep(PendingAwaitablePage|Webpage $page, string $step, array &$findings): void
{
    $page->waitForEvent('networkidle'); // let a save's redirect settle before auditing
    $result = $page->script(PAGE_AUDIT_SCRIPT);
    $findings[$step] = $result === null ? [] : pageAuditFindings($result);
}

/**
 * @param  array<string, list<string>>  $findings
 */
function scenarioAssertNoNewFindings(array $findings): void
{
    $baseline = json_decode((string) @file_get_contents(SCENARIO_BASELINE), true) ?? [];

    if (getenv('UPDATE_PAGE_AUDIT_BASELINE')) {
        $baseline = array_merge($baseline, $findings);
        ksort($baseline);
        file_put_contents(SCENARIO_BASELINE, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    $new = [];
    foreach ($findings as $step => $list) {
        foreach (array_diff($list, $baseline[$step] ?? []) as $finding) {
            $new[] = "{$step}: {$finding}";
        }
    }

    expect($new)->toBeEmpty("New findings along the scenario:\n".implode("\n", $new));
}

function scenarioOptionValue(PendingAwaitablePage|Webpage $page, string $selector, string $text): string
{
    $value = $page->script(sprintf(
        '() => [...document.querySelector(%s).options].find((o) => o.text.includes(%s))?.value ?? null',
        json_encode($selector),
        json_encode($text),
    ));
    expect($value)->not->toBeNull("No option containing \"{$text}\" in {$selector}");

    return (string) $value;
}

test('a developer files an issue, takes it on, comments and logs time', function () {
    [$project, $developer] = scenarioDeveloper();
    $findings = [];
    $subject = 'シナリオ: 決済確認画面で合計金額がずれる';

    $page = visit(route('issues.create', $project));
    scenarioStep($page, 'issue: new form', $findings);
    $page->fill('[wire\:model="subject"]', $subject)
        ->fill('[wire\:model="description"]', "再現手順\n1. カートに2点入れる\n2. 決済確認へ進む")
        ->press('保存')
        ->assertSee($subject);
    scenarioStep($page, 'issue: created', $findings);

    $issue = Issue::query()->where('subject', $subject)->firstOrFail();
    expect($issue->author_id)->toBe($developer->id)
        ->and($issue->project_id)->toBe($project->id);

    $inProgress = IssueStatus::query()->where('is_closed', false)->orderBy('position')->skip(1)->firstOrFail();
    $page = visit(route('issues.edit', [$project, $issue]));
    scenarioStep($page, 'issue: edit form', $findings);
    $page->select('[wire\:model\.live="status_id"]', (string) $inProgress->id)
        ->select('[wire\:model="assigneeChoice"]', scenarioOptionValue($page, '[wire\:model="assigneeChoice"]', $developer->name))
        ->fill('[wire\:model="comment"]', '原因を調査します。まず税計算の丸めを確認します。')
        ->fill('[wire\:model="logTimeHours"]', '1.5')
        ->press('保存')
        ->assertSee('原因を調査します。');
    scenarioStep($page, 'issue: updated with comment and time', $findings);

    $issue->refresh();
    expect($issue->status_id)->toBe($inProgress->id)
        ->and($issue->assigned_to_id)->toBe($developer->id)
        ->and((float) $issue->timeEntries()->sum('hours'))->toBe(1.5)
        ->and($issue->journals()->whereNotNull('notes')->exists())->toBeTrue();

    scenarioAssertNoNewFindings($findings);
});

test('a developer writes a wiki page, revises it and compares the revisions', function () {
    [$project] = scenarioDeveloper();
    $findings = [];

    $page = visit(route('wiki.create', $project));
    scenarioStep($page, 'wiki: new page form', $findings);
    $page->fill('[wire\:model="title"]', 'シナリオ手順書')
        ->fill('[wire\:model="text"]', "# シナリオ手順書\n\n初版の本文です。")
        ->press('保存')
        ->assertSee('初版の本文です。');
    scenarioStep($page, 'wiki: page created', $findings);

    $wikiPage = WikiPage::query()->where('project_id', $project->id)->where('title', 'シナリオ手順書')->firstOrFail();
    $page = visit(route('wiki.edit', [$project, $wikiPage]));
    $page->fill('[wire\:model="text"]', "# シナリオ手順書\n\n第二版で手順を追記しました。")
        ->fill('[wire\:model="comments"]', '手順を追記')
        ->press('保存')
        ->assertSee('第二版で手順を追記しました。');

    $page = visit(route('wiki.history', [$project, $wikiPage]));
    $page->assertSee('手順を追記');
    scenarioStep($page, 'wiki: history', $findings);

    $page = visit(route('wiki.diff', [$project, $wikiPage, 1, 2]));
    $page->assertSee('第二版で手順を追記しました。');
    scenarioStep($page, 'wiki: diff', $findings);

    expect($wikiPage->versions()->count())->toBe(2);

    scenarioAssertNoNewFindings($findings);
});

test('a developer starts a forum topic and replies to it', function () {
    [$project, $developer] = scenarioDeveloper();
    $findings = [];
    $board = Board::query()->where('project_id', $project->id)->firstOrFail();

    $page = visit(route('messages.create', [$project, $board]));
    scenarioStep($page, 'forum: new topic form', $findings);
    $page->fill('[wire\:model="subject"]', 'シナリオ: リリース前の確認事項')
        ->fill('[wire\:model="content"]', 'リリース前に確認すべき項目を洗い出しましょう。')
        ->press('保存')
        ->assertSee('リリース前に確認すべき項目を洗い出しましょう。');
    scenarioStep($page, 'forum: topic created', $findings);

    $page->fill('[wire\:model="replyContent"]', '決済まわりの回帰テストを追加しました。')
        ->press('返信を投稿')
        ->assertSee('決済まわりの回帰テストを追加しました。');
    scenarioStep($page, 'forum: replied', $findings);

    $topic = Message::query()->where('subject', 'シナリオ: リリース前の確認事項')->firstOrFail();
    expect($topic->author_id)->toBe($developer->id)
        ->and(Message::query()->where('parent_id', $topic->id)->where('author_id', $developer->id)->exists())->toBeTrue();

    scenarioAssertNoNewFindings($findings);
});
