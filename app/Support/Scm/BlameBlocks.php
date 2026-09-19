<?php

declare(strict_types=1);

namespace App\Support\Scm;

/**
 * Groups annotate lines into Redmine's colour blocks
 * (repositories/annotate.html.erb): consecutive lines from the same
 * revision form one block that shows the revision and author once, at its
 * first line; each distinct revision gets the next of 12 colours in order of
 * first appearance (`k.size % 12`), reused when it comes back; and the first
 * line of a block that follows a different one is marked as a change
 * boundary.
 */
final class BlameBlocks
{
    public const COLOR_COUNT = 12;

    /**
     * @param  array<int, ScmBlameLine>  $lines
     * @return array<int, array{showMeta: bool, colorIndex: int, isChange: bool}>
     */
    public static function annotate(array $lines): array
    {
        $colors = [];
        $blocks = [];
        $previousRevision = null;

        foreach (array_values($lines) as $index => $line) {
            $colors[$line->revision] ??= count($colors) % self::COLOR_COUNT;

            $startsBlock = $line->revision !== $previousRevision;

            $blocks[$index] = [
                'showMeta' => $startsBlock,
                'colorIndex' => $colors[$line->revision],
                'isChange' => $startsBlock && $previousRevision !== null,
            ];

            $previousRevision = $line->revision;
        }

        return $blocks;
    }
}
