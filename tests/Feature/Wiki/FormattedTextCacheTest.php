<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\Setting;
use App\Models\WikiPage;
use App\Support\Markdown\WikiMarkdownRenderer;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

function paddedMarkdown(string $head, int $bytes = 2200): string
{
    return $head."\n\n".str_repeat("filler text line\n", intdiv($bytes, 17));
}

test('nothing is cached while cache_formatted_text is off', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $text = paddedMarkdown("See #{$issue->id}");
    $renderer = app(WikiMarkdownRenderer::class);

    expect($renderer->render($text, $project))->toContain('href=');

    $issue->delete();

    expect($renderer->render($text, $project))->not->toContain('href=');
});

test('a text over 2 KB is served from the cache when the setting is on', function () {
    Setting::set('cache_formatted_text', true);
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $text = paddedMarkdown("See #{$issue->id}");
    $renderer = app(WikiMarkdownRenderer::class);

    $first = $renderer->render($text, $project);
    $issue->delete();

    expect($first)->toContain('href=')
        ->and($renderer->render($text, $project))->toBe($first);
});

test('a text of 2 KB or less is never cached', function () {
    Setting::set('cache_formatted_text', true);
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $text = "See #{$issue->id}";
    $renderer = app(WikiMarkdownRenderer::class);

    expect($renderer->render($text, $project))->toContain('href=');

    $issue->delete();

    expect($renderer->render($text, $project))->not->toContain('href=');
});

test('the cache is scoped to the project so identical text never leaks across projects', function () {
    Setting::set('cache_formatted_text', true);
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $issue = Issue::factory()->for($projectA)->create();
    $text = paddedMarkdown("See #{$issue->id} and [[Some Page]]");
    $renderer = app(WikiMarkdownRenderer::class);

    $forA = $renderer->render($text, $projectA);
    $forB = $renderer->render($text, $projectB);

    expect($forA)->not->toBe($forB);
});

test('a change to the text produces a fresh render', function () {
    Setting::set('cache_formatted_text', true);
    $renderer = app(WikiMarkdownRenderer::class);

    $one = $renderer->render(paddedMarkdown('# First heading'));
    $two = $renderer->render(paddedMarkdown('# Second heading'));

    expect($one)->toContain('First heading')->and($two)->toContain('Second heading');
});

test('pages using include or child_pages are never cached', function () {
    Setting::set('cache_formatted_text', true);
    $project = Project::factory()->create();
    $author = App\Models\User::factory()->create();
    $target = WikiPage::factory()->for($project)->create(['title' => 'Target']);
    $target->versions()->create(['author_id' => $author->id, 'text' => 'Original body', 'version' => 2]);
    $renderer = app(WikiMarkdownRenderer::class);
    $text = paddedMarkdown('{{include(Target)}}');

    expect($renderer->render($text, $project))->toContain('Original body');

    $target->versions()->create(['author_id' => $author->id, 'text' => 'Updated body', 'version' => 3]);

    expect($renderer->render($text, $project->fresh()))->toContain('Updated body');
});

test('the settings page saves cache_formatted_text', function () {
    $admin = App\Models\User::factory()->admin()->create();

    Livewire\Livewire::actingAs($admin)->test('settings.index')
        ->assertSet('cache_formatted_text', false)
        ->set('cache_formatted_text', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('cache_formatted_text', false))->toBeTrue();
});
