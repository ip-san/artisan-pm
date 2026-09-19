<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\WikiPage;
use App\Support\Markdown\MacroCall;
use App\Support\Markdown\WikiMacros;
use App\Support\Markdown\WikiMarkdownRenderer;
use Illuminate\Http\UploadedFile;

function macroRender(string $text, ?Project $project = null, ?WikiPage $page = null, $attachments = null): string
{
    return app(WikiMarkdownRenderer::class)->render($text, $project, $attachments, $page);
}

function macroViewer(Project $project, array $permissions = ['view_wiki_pages', 'view_issues']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

afterEach(fn () => WikiMacros::reset());

test('hello_world echoes its arguments', function () {
    expect(macroRender("{{hello_world(a, b)}}\n"))->toContain('Hello world! Arguments: a, b');
});

test('macro_list lists every macro including a registered one', function () {
    WikiMacros::register('shout', fn (MacroCall $call) => '<p>'.e(strtoupper(implode(' ', $call->arguments))).'</p>', 'Shouts.');

    $html = macroRender("{{macro_list}}\n\n{{shout(hi there)}}\n");

    expect($html)->toContain('{{recent_pages}}')->toContain('{{thumbnail}}')->toContain('{{shout}}')->toContain('Shouts.')->toContain('HI THERE');
});

test('recent_pages lists the newest pages first and honours the count', function () {
    $project = Project::factory()->create();
    macroViewer($project);
    WikiPage::factory()->for($project)->create(['title' => 'Oldest', 'updated_at' => now()->subDays(3)]);
    WikiPage::factory()->for($project)->create(['title' => 'Middle', 'updated_at' => now()->subDays(2)]);
    WikiPage::factory()->for($project)->create(['title' => 'Newest', 'updated_at' => now()->subDay()]);
    $viewer = macroViewer($project);

    $this->actingAs($viewer);
    $html = macroRender("{{recent_pages(2)}}\n", $project);

    expect($html)->toContain('<ul class="recent-pages">')->toContain('Newest')->toContain('Middle')->not->toContain('Oldest')
        ->and(strpos($html, 'Newest'))->toBeLessThan(strpos($html, 'Middle'));
});

test('recent_pages outside a project is reported, not rendered empty', function () {
    expect(macroRender("{{recent_pages}}\n"))->toContain('macro-error');
});

test('the issue macro links a visible issue with tracker, subject and status', function () {
    $project = Project::factory()->create();
    $viewer = macroViewer($project);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create(['name' => 'Bug'])->id,
        'status_id' => IssueStatus::factory()->create(['name' => 'Open'])->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Broken thing',
    ]);
    $this->actingAs($viewer);

    $html = macroRender("{{issue({$issue->id})}}\n", $project);
    expect($html)->toContain('Bug #'.$issue->id.': Broken thing')->toContain('(Open)')->toContain(route('issues.show', [$project, $issue]));

    $plain = macroRender("{{issue({$issue->id}, tracker=false, subject=false, status=false)}}\n", $project);
    expect($plain)->toContain('>#'.$issue->id.'<')->not->toContain('Broken thing');
});

test('the issue macro does not reveal an issue the reader may not see', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create(['subject' => 'Secret subject']);
    $outsider = User::factory()->create();
    $this->actingAs($outsider);

    $html = macroRender("{{issue({$issue->id})}}\n", $project);

    expect($html)->toContain('macro-error')->not->toContain('Secret subject');
});

test('thumbnail links an attached image and reports a missing one', function () {
    $project = Project::factory()->create();
    $author = User::factory()->create();
    $page = app(App\Services\WikiPageService::class)->create($project, ['title' => 'Home'], 'x', $author);
    $media = $page->addMedia(UploadedFile::fake()->image('photo.png'))->toMediaCollection('attachments');

    $html = macroRender("{{thumbnail(photo.png, size=150, title=Nice)}}\n", $project, $page, $page->attachments());
    expect($html)->toContain('href="'.route('attachments.show', $media).'"')->toContain('max-width: 150px')->toContain('title="Nice"');

    expect(macroRender("{{thumbnail(missing.png)}}\n", $project, $page, $page->attachments()))->toContain('macro-error');
});

test('child_pages nests to the given depth and can show the parent', function () {
    $project = Project::factory()->create();
    $parent = WikiPage::factory()->for($project)->create(['title' => 'Root']);
    $child = WikiPage::factory()->for($project)->for($parent, 'parent')->create(['title' => 'Child']);
    WikiPage::factory()->for($project)->for($child, 'parent')->create(['title' => 'Grandchild']);

    $shallow = macroRender("{{child_pages}}\n", $project, $parent);
    $deep = macroRender("{{child_pages(depth=2)}}\n", $project, $parent);
    $withParent = macroRender("{{child_pages(parent=1)}}\n", $project, $parent);

    expect($shallow)->toContain('Child')->not->toContain('Grandchild')
        ->and($deep)->toContain('Grandchild')
        ->and($withParent)->toContain('child-pages-parent')->toContain('Root');
});

test('child_pages can name another page of the project', function () {
    $project = Project::factory()->create();
    $home = WikiPage::factory()->for($project)->create(['title' => 'Home']);
    $other = WikiPage::factory()->for($project)->create(['title' => 'Other']);
    WikiPage::factory()->for($project)->for($other, 'parent')->create(['title' => 'Under Other']);

    expect(macroRender("{{child_pages(Other)}}\n", $project, $home))->toContain('Under Other')
        ->and(macroRender("{{child_pages(Nowhere)}}\n", $project, $home))->toContain('macro-error');
});

test('include reaches another project only for a reader allowed to see that page', function () {
    $home = Project::factory()->create();
    $other = Project::factory()->private()->create(['identifier' => 'other-proj']);
    $page = WikiPage::factory()->for($other)->create(['title' => 'Shared']);
    $page->versions()->create(['author_id' => User::factory()->create()->id, 'text' => 'Included body text', 'version' => 2]);
    $insider = macroViewer($other);
    $outsider = macroViewer($home);

    $this->actingAs($insider);
    expect(macroRender("{{include(other-proj:Shared)}}\n", $home))->toContain('Included body text');

    $this->actingAs($outsider);
    $html = macroRender("{{include(other-proj:Shared)}}\n", $home);
    expect($html)->not->toContain('Included body text')->toContain('見つかりません');
});

test('an unknown macro name is left as literal text', function () {
    expect(macroRender("{{no_such_macro}}\n"))->toContain('{{no_such_macro}}');
});

test('macros whose output can change are never served from the formatted-text cache', function () {
    expect(WikiMacros::isDynamic("{{recent_pages}}"))->toBeTrue()
        ->and(WikiMacros::isDynamic("{{issue(1)}}"))->toBeTrue()
        ->and(WikiMacros::isDynamic("{{child_pages(depth=2)}}"))->toBeTrue()
        ->and(WikiMacros::isDynamic("{{include(a:B)}}"))->toBeTrue()
        ->and(WikiMacros::isDynamic("{{toc}} and {{hello_world}}"))->toBeFalse();
});
