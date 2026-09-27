<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;

test('the issue list keeps one prominent action and tucks the rest away', function () {
    $project = Project::factory()->create();

    $html = $this->actingAs(User::factory()->admin()->create())->get(route('issues.index', $project))->assertOk()->getContent();

    // Reports, exports and feeds sit in the "その他" menu, which starts closed.
    expect($html)->toMatch('#data-issue-list-more>.*?<div x-show="moreOpen" x-cloak.*?'.preg_quote(route('issues.report', $project), '#').'.*?data-csv-export-options#s')
        // Grouping, totals, columns and sort sit under "表示設定", also closed at first.
        ->and($html)->toMatch('#<div id="issue-display-settings" x-show="displayOpen" x-cloak[^>]*>.*?グループ化:.*?表示列:#s')
        ->and($html)->toContain('aria-pressed="true"');
});

test('the issue page keeps editing in view and the rarer actions in a menu', function () {
    $project = Project::factory()->create();
    Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();

    $html = $this->actingAs(User::factory()->admin()->create())->get(route('issues.show', [$project, $issue]))->assertOk()->getContent();

    expect($html)->toContain('href="'.route('issues.edit', [$project, $issue]).'" class="btn btn-primary"')
        ->and($html)->toMatch('#data-issue-more>.*?<div x-show="moreOpen" x-cloak.*?'.preg_quote(route('issues.pdf', [$project, $issue]), '#').'#s')
        // The move form waits until "別のプロジェクトへ移動" is chosen.
        ->and($html)->toContain('x-data="{ moveOpen: false }"')
        ->and($html)->toContain('x-show="moveOpen" x-cloak');
});
