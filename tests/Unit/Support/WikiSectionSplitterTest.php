<?php

use App\Support\Markdown\WikiSectionSplitter;

beforeEach(function () {
    $this->splitter = new WikiSectionSplitter;

    $this->text = <<<'MD'
Intro paragraph before any heading.

# Section One

Content of section one.

## Subsection One

Nested content.

# Section Two

~~~
# not a real heading
~~~

Real content after the fence.
MD;
});

test('an h1 section absorbs its nested subsection', function () {
    $result = $this->splitter->extractSections($this->text, 1);

    expect($result[0])->toBe('Intro paragraph before any heading.')
        ->and($result[1])->toContain('# Section One')
        ->toContain('## Subsection One')
        ->toContain('Nested content.')
        ->not->toContain('# Section Two')
        ->and($result[2])->toContain('# Section Two')
        ->toContain('not a real heading');
});

test('an h2 subsection is isolated from its parent and siblings', function () {
    $result = $this->splitter->extractSections($this->text, 2);

    expect($result[0])->toContain('# Section One')
        ->not->toContain('## Subsection One')
        ->and($result[1])->toBe("## Subsection One\n\nNested content.")
        ->and($result[2])->toContain('# Section Two');
});

test('a fenced code block is never mistaken for a heading', function () {
    $result = $this->splitter->extractSections($this->text, 3);

    expect($result[1])->toContain('# Section Two')
        ->toContain('not a real heading')
        ->toContain('Real content after the fence.')
        ->and($result[2])->toBe('');
});

test('an out-of-range index returns the entire text as the "before" segment', function () {
    $result = $this->splitter->extractSections($this->text, 4);

    expect($result[0])->toBe(trim($this->text))
        ->and($result[1])->toBe('')
        ->and($result[2])->toBe('');
});

test('setext headings are recognized and their level is derived from the underline', function () {
    $setext = <<<'MD'
Title
=====

Content under the title.

Sub
---

More content.
MD;

    $topLevel = $this->splitter->extractSections($setext, 1);
    expect($topLevel[1])->toContain('Title')
        ->toContain('Sub')
        ->toContain('More content.');

    $subLevel = $this->splitter->extractSections($setext, 2);
    expect($subLevel[0])->toContain('Title')
        ->not->toContain('Sub')
        ->and($subLevel[1])->toBe("Sub\n---\n\nMore content.");
});

test('updateSection splices the replacement into the correct section, leaving everything else unchanged', function () {
    $replacement = "## Subsection One\n\nReplaced content.";

    $result = $this->splitter->updateSection($this->text, 2, $replacement);

    expect($result['section'])->toBe($replacement)
        ->and($result['text'])->toContain('Intro paragraph before any heading.')
        ->toContain('# Section One')
        ->toContain($replacement)
        ->not->toContain('Nested content.')
        ->and($result['text'])->toContain('# Section Two');
});

test('updateSection leaves the text unchanged when the index is out of range', function () {
    $result = $this->splitter->updateSection($this->text, 99, 'Should not appear anywhere.');

    expect($result['section'])->toBe('')
        ->and($result['text'])->not->toContain('Should not appear anywhere.');
});
