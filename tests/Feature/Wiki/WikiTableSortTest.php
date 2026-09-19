<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\Markdown\WikiMarkdownRenderer;
use Livewire\Livewire;

function sortableTableMarkdown(int $bodyRows): string
{
    $rows = collect(range(1, $bodyRows))->map(fn (int $n) => "| item {$n} | {$n} |")->implode("\n");

    return "| Name | Qty |\n|---|---|\n{$rows}\n";
}

test('with the setting on, a table with a header and two body rows is marked sortable', function () {
    Setting::set('wiki_tablesort_enabled', true);

    expect(app(WikiMarkdownRenderer::class)->render(sortableTableMarkdown(2)))->toContain('<table data-tablesort="1">');
});

test('a table with a single body row, or no header, is left alone', function () {
    Setting::set('wiki_tablesort_enabled', true);

    expect(app(WikiMarkdownRenderer::class)->render(sortableTableMarkdown(1)))->not->toContain('data-tablesort');
});

test('with the setting off (the default) no table is marked', function () {
    expect(app(WikiMarkdownRenderer::class)->render(sortableTableMarkdown(5)))->not->toContain('data-tablesort');
});

test('turning the setting on changes the cached rendering of a long page', function () {
    Setting::set('cache_formatted_text', true);
    $text = sortableTableMarkdown(120);
    $renderer = app(WikiMarkdownRenderer::class);

    expect($renderer->render($text))->not->toContain('data-tablesort');

    Setting::set('wiki_tablesort_enabled', true);
    expect($renderer->render($text))->toContain('data-tablesort');
});

test('the settings page saves the switch', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('wiki_tablesort_enabled', true)->call('save')->assertHasNoErrors();

    expect(Setting::get('wiki_tablesort_enabled'))->toBeTrue();
});
