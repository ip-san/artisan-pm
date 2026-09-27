<?php

use App\Enums\EnumerationType;
use App\Models\AuthSource;
use App\Models\Board;
use App\Models\CustomField;
use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Models\Webhook;
use App\Models\Wiki;
use App\Models\WikiPage;
use App\Models\WikiPageVersion;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Crawls every HTML GET page as an administrator and checks it with axe (critical/serious)
 * and three layout detectors. Findings recorded in baselines/page-audit.json are tolerated;
 * anything new fails. Refresh the baseline with UPDATE_PAGE_AUDIT_BASELINE=1 after fixing pages.
 */
const PAGE_AUDIT_BASELINE = __DIR__.'/baselines/page-audit.json';

const PAGE_AUDIT_SKIP = '#^(api/|livewire|oauth|storage/|sanctum|up$|_ignition|telescope|_boost|_debugbar)'
    .'|\.(atom|csv|pdf|png|json|xml|txt|zip)($|/)|/(download|raw|export|csv|pdf|atom|thumbnail)(/|$)|logout|mail_handler|sys/#';

const PAGE_AUDIT_SCRIPT = <<<'JS'
async () => {
    if (document.readyState !== 'complete') {
        await new Promise((resolve) => addEventListener('load', resolve, { once: true }));
    }
    if (!document.contentType.startsWith('text/html')) {
        return null;
    }
    const out = { error: null, js: [], axe: [], layout: [] };
    if (/server error|not found|forbidden|page expired|unauthorized/i.test(document.title)) {
        out.error = document.title;
    }
    out.js = [...new Set((window.__pestBrowser?.jsErrors ?? []).map((e) => String(e.message).slice(0, 120)))].sort();

    const axe = await window.axe.run(document, { resultTypes: ['violations'] });
    const violations = axe.violations.filter((v) => ['critical', 'serious'].includes(v.impact));
    out.axe = [...new Set(violations.map((v) => v.id))].sort();
    // Which elements failed, so a fix can be traced back to its template (see PAGE_AUDIT_DETAILS).
    const describe = (target) => {
        const el = document.querySelector(target);
        if (!el) return target;
        const key = [...el.attributes].find((a) => /^(wire:model|name|id|href)/.test(a.name));
        const text = (el.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 30);
        return `${el.tagName.toLowerCase()}${key ? `[${key.name}=${key.value.slice(0, 40)}]` : ''}${text ? ` "${text}"` : ''}`;
    };
    out.details = Object.fromEntries(violations.map((v) => [v.id, [...new Set(v.nodes.map((n) => describe(n.target[0])))]]));

    const viewport = document.documentElement.clientWidth;
    const label = (kind, el) => {
        const text = (el.innerText || el.value || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 40);
        out.layout.push(`${kind}: ${el.tagName.toLowerCase()}${text ? ` "${text}"` : ''}`);
    };
    const style = (el) => getComputedStyle(el);
    const scrollsX = (el) => /auto|scroll/.test(style(el).overflowX);
    const clipsX = (el) => /hidden|clip/.test(style(el).overflowX);
    const boxed = (el) => {
        const s = style(el);
        return parseFloat(s.borderRightWidth) > 0 || !['rgba(0, 0, 0, 0)', 'transparent'].includes(s.backgroundColor);
    };

    if (document.scrollingElement.scrollWidth > viewport + 1) {
        out.layout.push('page: horizontal scroll');
    }

    // Tables and code blocks may scroll sideways; anything else hiding content behind a scrollbar
    // (e.g. a header menu whose scrollbar macOS hides) reads as cut off.
    for (const el of document.body.querySelectorAll('*')) {
        if (el.offsetParent === null || !scrollsX(el) || el.scrollWidth <= el.clientWidth + 1) continue;
        if (el.querySelector('table, pre, code') || /^(TABLE|PRE|CODE|TEXTAREA)$/.test(el.tagName)) continue;
        label('hidden-scroll', el);
    }

    for (const el of document.body.querySelectorAll('*')) {
        if (el.offsetParent === null || style(el).visibility === 'hidden') continue;
        const isControl = /^(INPUT|SELECT|TEXTAREA|BUTTON)$/.test(el.tagName);
        const ownText = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim());
        if (!isControl && !ownText) continue;
        const rect = el.getBoundingClientRect();
        if (rect.width <= 1 || rect.height <= 1) continue;

        let finding = null;
        let insideScroller = false;
        for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
            if (scrollsX(a)) { insideScroller = true; break; }
            const box = a.getBoundingClientRect();
            const escapes = rect.right > box.right + 1 || rect.left < box.left - 1;
            if (clipsX(a)) { if (escapes) finding = 'clipped'; break; }
            if (boxed(a)) { if (escapes) finding = 'overflow'; break; }
        }
        if (!finding && !insideScroller && rect.right > viewport + 1) finding = 'overflow';
        if (!finding && ownText && !el.title && !el.getAttribute('aria-label')) {
            const s = style(el);
            if ((clipsX(el) || s.textOverflow === 'ellipsis') && el.scrollWidth > el.clientWidth + 1) finding = 'clipped';
        }
        if (finding) label(finding, el);
    }

    // A dropdown (<details> panel) inside a box that clips overflow can never be seen when opened.
    for (const el of document.body.querySelectorAll('details')) {
        if (el.offsetParent === null) continue;
        for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
            const s = style(a);
            if (s.overflowX !== 'visible' || s.overflowY !== 'visible') { label('popover-clipped', el.querySelector('summary') ?? el); break; }
        }
    }

    // A short button label squeezed onto two lines ("CSVエクスポー / ト").
    for (const el of document.body.querySelectorAll('button, a')) {
        const text = (el.innerText || '').trim();
        if (el.offsetParent === null || !boxed(el) || text.length === 0 || text.length > 20 || text.includes('\n')) continue;
        const s = style(el);
        const lineHeight = parseFloat(s.lineHeight) || parseFloat(s.fontSize) * 1.5;
        const content = el.clientHeight - parseFloat(s.paddingTop) - parseFloat(s.paddingBottom);
        if (content > lineHeight * 1.5) label('wrapped-label', el);
    }

    // A select's chosen text cut off under its arrow.
    const canvas = document.createElement('canvas').getContext('2d');
    for (const el of document.body.querySelectorAll('select:not([multiple])')) {
        if (el.offsetParent === null || !el.selectedOptions[0]) continue;
        const s = style(el);
        canvas.font = s.font;
        const room = el.clientWidth - parseFloat(s.paddingLeft) - parseFloat(s.paddingRight);
        if (canvas.measureText(el.selectedOptions[0].text).width > room + 1) label('select-cut', el);
    }

    // Inputs, selects and buttons sitting side by side at different heights.
    const controls = [...document.body.querySelectorAll(
        'select, button, input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=file]), textarea:not([rows])'
    )].filter((el) => el.offsetParent !== null && boxed(el)); // link-styled buttons are meant to be shorter
    for (const parent of new Set(controls.map((el) => el.parentElement))) {
        const row = controls.filter((el) => el.parentElement === parent).map((el) => el.getBoundingClientRect());
        for (let i = 0; i < row.length; i++) {
            for (let j = i + 1; j < row.length; j++) {
                const sameLine = row[i].top < row[j].bottom && row[j].top < row[i].bottom;
                if (sameLine && Math.abs(row[i].height - row[j].height) > 4) { label('height-mismatch', parent); i = row.length; break; }
            }
        }
    }

    out.layout = [...new Set(out.layout)].sort();
    return out;
}
JS;

/**
 * @param  array<string, mixed>  $fixtures
 * @return array<string, mixed>|null
 */
function pageAuditParameters(RoutingRoute $route, array $fixtures): ?array
{
    $parameters = [];

    foreach ($route->parameterNames() as $name) {
        $wikiRevision = str_starts_with((string) $route->getName(), 'wiki.')
            ? ['version' => 1, 'from' => 1, 'to' => 2]
            : [];

        if (array_key_exists($name, $wikiRevision)) {
            $parameters[$name] = $wikiRevision[$name];
        } elseif (array_key_exists($name, $fixtures)) {
            $parameters[$name] = $fixtures[$name];
        } elseif (! str_ends_with($route->uri(), '{'.$name.'?}')) {
            return null;
        }
    }

    return $parameters;
}

/**
 * @return array<string, mixed>
 */
function pageAuditFixtures(User $admin): array
{
    $project = Project::query()->where('identifier', 'demo-project')->firstOrFail();
    $board = Board::factory()->for($project)->create();
    $wikiPage = WikiPage::factory()->create(['project_id' => $project->id, 'title' => 'Wiki']);
    Wiki::factory()->create(['project_id' => $project->id, 'start_page' => 'Wiki']);
    WikiPageVersion::factory()->create(['wiki_page_id' => $wikiPage->id, 'author_id' => $admin->id, 'version' => $wikiPage->versions()->max('version') + 1]);

    return [
        'project' => $project,
        'issue' => Issue::query()->where('project_id', $project->id)->firstOrFail(),
        'board' => $board,
        'message' => Message::factory()->for($board)->create(['author_id' => $admin->id]),
        'document' => Document::factory()->for($project)->create(),
        'news' => News::factory()->for($project)->create(['author_id' => $admin->id]),
        'version' => Version::factory()->for($project)->create(),
        'wikiPage' => $wikiPage,
        'timeEntry' => TimeEntry::factory()->for($project)->create(['user_id' => $admin->id]),
        'issueCategory' => IssueCategory::factory()->for($project)->create(),
        'customField' => CustomField::query()->firstOrFail(),
        'type' => EnumerationType::IssuePriority->value,
        'enumeration' => Enumeration::query()->ofType(EnumerationType::IssuePriority)->firstOrFail(),
        'group' => Group::factory()->create(),
        'issueStatus' => IssueStatus::query()->firstOrFail(),
        'role' => Role::query()->where('name', 'Manager')->firstOrFail(),
        'tracker' => Tracker::query()->firstOrFail(),
        'user' => User::factory()->create(),
        'webhook' => Webhook::factory()->create(),
        'authSource' => AuthSource::factory()->create(),
    ];
}

test('every page passes axe and the layout checks, apart from recorded findings', function () {
    $this->seed();
    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
    $fixtures = pageAuditFixtures($admin);
    $this->actingAs($admin);

    $pages = [];
    $unmapped = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true) || $route->getName() === null || preg_match(PAGE_AUDIT_SKIP, $route->uri())) {
            continue;
        }

        $parameters = pageAuditParameters($route, $fixtures);

        if ($parameters === null) {
            $unmapped[] = $route->getName();

            continue;
        }

        $pages[$route->getName()] = visit(route($route->getName(), $parameters, false))->script(PAGE_AUDIT_SCRIPT);
    }

    $pages = array_filter($pages); // JSON and other non-HTML endpoints come back as null

    // English labels are longer, so re-check the shared chrome (header, filters) in English too.
    $admin->update(['language' => 'en']);
    foreach (['projects.index', 'issues.global-index', 'my-page.index', 'admin.info'] as $name) {
        $pages["en:{$name}"] = visit(route($name, [], false))->script(PAGE_AUDIT_SCRIPT);
    }

    // Pages only a signed-out visitor sees.
    auth()->forgetGuards();
    foreach (['login', 'register', 'password.request'] as $name) {
        $pages["guest:{$name}"] = visit(route($name, [], false))->script(PAGE_AUDIT_SCRIPT);
    }

    ksort($pages);
    sort($unmapped);

    if ($detailsPath = getenv('PAGE_AUDIT_DETAILS')) {
        file_put_contents($detailsPath, json_encode(array_map(fn (array $page) => $page['details'], $pages), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $pages = array_map(fn (array $page) => array_diff_key($page, ['details' => true]), $pages);
    $current = ['unmapped' => $unmapped, 'pages' => $pages];

    if (getenv('UPDATE_PAGE_AUDIT_BASELINE')) {
        @mkdir(dirname(PAGE_AUDIT_BASELINE), 0777, true);
        file_put_contents(PAGE_AUDIT_BASELINE, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    $baseline = json_decode((string) @file_get_contents(PAGE_AUDIT_BASELINE), true) ?? ['unmapped' => [], 'pages' => []];
    $regressions = [];

    foreach (array_diff($unmapped, $baseline['unmapped']) as $name) {
        $regressions[] = "{$name}: new route with a parameter the audit cannot fill (add a fixture or record it in the baseline)";
    }

    foreach ($pages as $name => $result) {
        $known = ($baseline['pages'][$name] ?? []) + ['error' => null, 'js' => [], 'axe' => [], 'layout' => []];

        if ($result['error'] !== null && $known['error'] === null) {
            $regressions[] = "{$name}: error page \"{$result['error']}\"";
        }

        foreach (array_diff($result['js'], $known['js']) as $message) {
            $regressions[] = "{$name}: JavaScript error \"{$message}\"";
        }

        foreach (array_diff($result['axe'], $known['axe']) as $rule) {
            $regressions[] = "{$name}: axe {$rule}";
        }

        foreach (array_diff($result['layout'], $known['layout']) as $finding) {
            $regressions[] = "{$name}: {$finding}";
        }
    }

    expect($regressions)->toBeEmpty("New page-audit findings:\n".implode("\n", $regressions));
});
