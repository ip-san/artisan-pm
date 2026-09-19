<?php

use App\Support\Scm\BlameBlocks;
use App\Support\Scm\ScmBlameLine;

function blameLines(string ...$revisions): array
{
    return array_map(fn (string $revision) => new ScmBlameLine($revision, 'Someone', 'line'), $revisions);
}

test('consecutive lines of one revision form a block that shows metadata once', function () {
    $blocks = BlameBlocks::annotate(blameLines('a', 'a', 'a', 'b', 'b'));

    expect(array_column($blocks, 'showMeta'))->toBe([true, false, false, true, false]);
});

test('each new revision gets the next colour and a returning revision keeps its own', function () {
    $blocks = BlameBlocks::annotate(blameLines('a', 'b', 'a', 'c'));

    expect(array_column($blocks, 'colorIndex'))->toBe([0, 1, 0, 2]);
});

test('colours wrap after twelve distinct revisions', function () {
    $revisions = array_map(fn (int $i) => "rev{$i}", range(0, 12));

    $blocks = BlameBlocks::annotate(blameLines(...$revisions));

    expect($blocks[11]['colorIndex'])->toBe(11)
        ->and($blocks[12]['colorIndex'])->toBe(0);
});

test('a change boundary is marked only where a block follows a different one', function () {
    $blocks = BlameBlocks::annotate(blameLines('a', 'a', 'b', 'a'));

    expect(array_column($blocks, 'isChange'))->toBe([false, false, true, true]);
});

test('an empty file has no blocks', function () {
    expect(BlameBlocks::annotate([]))->toBe([]);
});
