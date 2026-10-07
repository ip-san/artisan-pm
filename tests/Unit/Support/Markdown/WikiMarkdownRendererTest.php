<?php

use App\Support\Markdown\WikiMarkdownRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The renderer reads Setting::get('wiki_tablesort_enabled'), so it needs the settings table; the
// Unit suite does not migrate by default, and relying on a previous Feature run having left the
// tables behind made this file fail whenever the testing database was empty.
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->renderer = app(WikiMarkdownRenderer::class);
});

test('plain Markdown renders without a project', function () {
    $html = $this->renderer->render("**bold** and _italic_\n\n- one\n- two");

    expect($html)->toContain('<strong>bold</strong>')
        ->toContain('<em>italic</em>')
        ->toContain('<li>one</li>');
});

test('a [[Page]] link is left as literal text without a project to resolve it against', function () {
    $html = $this->renderer->render('See [[Some Page]] for details.');

    expect($html)->toContain('[[Some Page]]')
        ->not->toContain('<a ');
});

test('an {{include(...)}} macro is left as literal text without a project', function () {
    $html = $this->renderer->render('{{include(Some Page)}}');

    expect($html)->toContain('{{include(Some Page)}}');
});

test('a {{toc}} macro still resolves without a project, since it needs no project context', function () {
    $html = $this->renderer->render("{{toc}}\n\n# Heading One");

    expect($html)->toContain('Heading One')
        ->not->toContain('{{toc}}');
});
