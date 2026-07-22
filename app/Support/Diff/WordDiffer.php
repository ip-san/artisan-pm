<?php

declare(strict_types=1);

namespace App\Support\Diff;

/**
 * Word-level diff between two texts — matches Redmine's Redmine::Helpers::
 * Diff (used to render WikiDiff), which also splits on whitespace and
 * diffs the resulting word arrays rather than diffing whole lines. Uses a
 * standard LCS dynamic-programming table; fine for typical wiki page
 * sizes, but is O(wordsFrom * wordsTo) in time and memory like Redmine's
 * own approach.
 */
final class WordDiffer
{
    /**
     * @return list<array{type: 'same'|'add'|'del', text: string}>
     */
    public function diff(string $from, string $to): array
    {
        $fromWords = $this->splitPreservingWhitespace($from);
        $toWords = $this->splitPreservingWhitespace($to);

        return (new LcsDiffer)->diff($fromWords, $toWords);
    }

    /**
     * @return list<string>
     */
    private function splitPreservingWhitespace(string $text): array
    {
        $parts = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return $parts !== false ? $parts : [];
    }
}
