<?php

declare(strict_types=1);

namespace App\Support\Markdown;

/**
 * Splits Markdown text into three parts around a target heading section:
 * everything before it, the section itself (its heading line through the
 * line before the next heading of equal-or-higher level), and everything
 * after. Matches Redmine's Redmine::WikiFormatting::SectionHelper (the
 * CommonMark formatter's section-edit support) — sections are indexed
 * sequentially by heading occurrence (1-based; index 0 never matches,
 * same as Redmine, so the preamble before the first heading isn't
 * independently section-editable). ATX (`#`) and Setext (underlined)
 * headings are both recognized; text inside ~~~/``` fences is not
 * scanned for headings.
 */
final class WikiSectionSplitter
{
    /**
     * @return array{0: string, 1: string, 2: string} before/section/after
     */
    public function extractSections(string $text, int $index): array
    {
        $parts = preg_split(
            '/^((?:(?!\s*$|#).+\r?\n\r?(?:=+|-+)\s*|#+ .+|(?:~~~|```).*)\s*)$/m',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($parts === false) {
            return ['', '', ''];
        }

        $sections = ['', '', ''];
        $offset = 0;
        $i = 0;
        $currentLevel = 1;
        $insidePre = false;

        foreach ($parts as $part) {
            $level = null;

            if (preg_match('/\A(~{3,}|`{3,})(\s*\S+)?\s*$/', $part, $matches) === 1) {
                if (! $insidePre) {
                    $insidePre = true;
                } elseif (! isset($matches[2])) {
                    $insidePre = false;
                }
            } elseif ($insidePre) {
                // nop — inside a fenced code block, never a heading.
            } elseif (preg_match('/\A(#+) .+/', $part, $matches) === 1) {
                $level = strlen($matches[1]);
            } elseif (preg_match('/\A.+\r?\n\r?(=+|-+)\s*$/', $part, $matches) === 1) {
                $level = str_contains($matches[1], '=') ? 1 : 2;
            }

            if ($level !== null) {
                $i++;

                if ($offset === 0 && $i === $index) {
                    $offset = 1;
                    $currentLevel = $level;
                } elseif ($offset === 1 && $i > $index && $level <= $currentLevel) {
                    $offset = 2;
                }
            }

            $sections[$offset] .= $part;
        }

        return array_map(trim(...), $sections);
    }

    /**
     * @return array{text: string, section: string}
     */
    public function updateSection(string $text, int $index, string $replacement): array
    {
        $sections = $this->extractSections($text, $index);

        if ($sections[1] !== '') {
            $sections[1] = $replacement;
        }

        return [
            'text' => implode("\n\n", array_filter($sections, fn (string $s) => $s !== '')),
            'section' => $sections[1],
        ];
    }
}
