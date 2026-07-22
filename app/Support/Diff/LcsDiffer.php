<?php

declare(strict_types=1);

namespace App\Support\Diff;

/**
 * Generic longest-common-subsequence diff over two token arrays — shared
 * DP-table/backtrack core used by both WordDiffer (word tokens, for the
 * wiki version-diff view) and WikiAnnotator (line tokens, for wiki
 * blame). O(m*n) in time and memory like Redmine's own diff approach;
 * fine for typical wiki page sizes.
 */
final class LcsDiffer
{
    /**
     * @param  list<string>  $from
     * @param  list<string>  $to
     * @return list<array{type: 'same'|'add'|'del', text: string}>
     */
    public function diff(array $from, array $to): array
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
