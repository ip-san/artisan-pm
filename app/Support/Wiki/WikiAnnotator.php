<?php

declare(strict_types=1);

namespace App\Support\Wiki;

use App\Models\User;
use App\Models\WikiPageVersion;
use App\Support\Diff\LcsDiffer;

/**
 * Line-by-line "who last touched this line" for a wiki page version,
 * matching Redmine's WikiAnnotate — a git-blame-style view, not a
 * one-shot diff of the current version against its immediate
 * predecessor. Each version stores its full text (not a diff against the
 * previous one), so this walks BACKWARD from the target version toward
 * version 1, line-diffing each adjacent pair and using an index-remapping
 * array to track which line of the ORIGINAL (target-version) text each
 * diff line corresponds to as it goes further back. The first version
 * found (walking backward) that introduced a line is the one credited —
 * any line never reintroduced all the way back to version 1 is credited
 * to version 1 itself.
 */
final class WikiAnnotator
{
    /**
     * @return list<array{version: int, author: ?User, text: string}>
     */
    public function annotate(WikiPageVersion $version): array
    {
        $versions = $version->wikiPage->versions()
            ->where('version', '<=', $version->version)
            ->with('author')
            ->get()
            ->keyBy('version');

        $lines = $this->splitLines($version->text);
        $lineCount = count($lines);

        $stampedVersion = array_fill(0, $lineCount, null);
        $stampedAuthor = array_fill(0, $lineCount, null);

        // positions[i] = index into the ORIGINAL $lines this iteration's
        // "current" text line i corresponds to, or null once that line's
        // origin has been traced past (deleted in an even older version).
        $positions = range(0, $lineCount - 1);
        $current = $version;

        while ($current->version > 1) {
            $previous = $versions->get($current->version - 1);

            if ($previous === null) {
                break;
            }

            $previousLines = $this->splitLines($previous->text);
            $currentLines = $this->splitLines($current->text);
            $ops = (new LcsDiffer)->diff($previousLines, $currentLines);

            $newPositions = [];
            $newIndex = 0;

            foreach ($ops as $op) {
                if ($op['type'] === 'add') {
                    $origIndex = $positions[$newIndex] ?? null;

                    if ($origIndex !== null && $stampedVersion[$origIndex] === null) {
                        $stampedVersion[$origIndex] = $current->version;
                        $stampedAuthor[$origIndex] = $current->author;
                    }

                    $newIndex++;
                } elseif ($op['type'] === 'del') {
                    $newPositions[] = null;
                } else {
                    $newPositions[] = $positions[$newIndex] ?? null;
                    $newIndex++;
                }
            }

            $positions = $newPositions;
            $current = $previous;
        }

        $firstVersion = $versions->get(1);
        $firstVersionNumber = $firstVersion !== null ? $firstVersion->version : 1;
        $firstVersionAuthor = $firstVersion?->author;

        foreach ($stampedVersion as $index => $stamped) {
            if ($stamped === null) {
                $stampedVersion[$index] = $firstVersionNumber;
                $stampedAuthor[$index] = $firstVersionAuthor;
            }
        }

        return array_map(fn (int $index) => [
            'version' => $stampedVersion[$index],
            'author' => $stampedAuthor[$index],
            'text' => $lines[$index],
        ], array_keys($lines));
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $text);

        return $lines !== false ? $lines : [];
    }
}
