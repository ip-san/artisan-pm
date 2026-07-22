<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use DOMXPath;

/**
 * Appends a small "編集" link inside every rendered heading of a wiki
 * page's HTML, numbered by document order — the same order
 * WikiSectionSplitter assigns section indices in, since both simply
 * count headings as encountered and neither is fooled by a
 * "# heading-looking" line inside a fenced code block (CommonMark never
 * renders that as a real <h#> tag in the first place). Matches Redmine's
 * per-section edit links.
 *
 * This lives in its own class rather than inline in the wiki Volt
 * component because HtmlFragment's DOM-loading string contains a literal
 * `?>` (the `<?xml encoding="utf-8"?>` prologue used to force libxml to
 * treat the fragment as UTF-8) — embedded in a Volt single-file
 * component's PHP block, that substring is indistinguishable from the
 * block's own closing tag and truncates it.
 */
final class WikiSectionEditLinkInjector
{
    public function inject(string $html, string $editUrl): string
    {
        $document = HtmlFragment::load($html);

        // XPath results come back in document order, so headings of
        // different levels are numbered in the order they appear.
        $headings = (new DOMXPath($document))->query('//h1|//h2|//h3|//h4|//h5|//h6');

        if ($headings === false || $headings->length === 0) {
            return $html;
        }

        foreach ($headings as $index => $heading) {
            $link = $document->createElement('a');
            $link->setAttribute('href', "{$editUrl}?section=".($index + 1));
            $link->setAttribute('class', 'section-edit ml-2 text-xs font-normal text-indigo-600 no-underline hover:underline');
            $link->textContent = '編集';
            $heading->appendChild($link);
        }

        return HtmlFragment::innerHtml($document);
    }
}
