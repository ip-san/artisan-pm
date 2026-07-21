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

        return $this->lcsDiff($fromWords, $toWords);
    }

    /**
     * @return list<string>
     */
    private function splitPreservingWhitespace(string $text): array
    {
        $parts = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return $parts !== false ? $parts : [];
    }

    /**
     * @param  list<string>  $from
     * @param  list<string>  $to
     * @return list<array{type: 'same'|'add'|'del', text: string}>
     */
    private function lcsDiff(array $from, array $to): array
    {
        $m = count($from);
        $n = count($to);

        $lengths = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $lengths[$i][$j] = $from[$i - 1] === $to[$j - 1]
                    ? $lengths[$i - 1][$j - 1] + 1
                    : max($lengths[$i - 1][$j], $lengths[$i][$j - 1]);
            }
        }

        $result = [];
        $i = $m;
        $j = $n;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $from[$i - 1] === $to[$j - 1]) {
                $result[] = ['type' => 'same', 'text' => $from[$i - 1]];
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $lengths[$i][$j - 1] >= $lengths[$i - 1][$j])) {
                $result[] = ['type' => 'add', 'text' => $to[$j - 1]];
                $j--;
            } else {
                $result[] = ['type' => 'del', 'text' => $from[$i - 1]];
                $i--;
            }
        }

        return array_reverse($result);
    }
}
