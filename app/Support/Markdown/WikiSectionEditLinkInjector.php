<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use DOMDocument;

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
 * component because its DOM-loading string contains a literal `?>` (the
 * `<?xml encoding="utf-8"?>` prologue used to force libxml to treat the
 * fragment as UTF-8) — embedded in a Volt single-file component's PHP
 * block, that substring is indistinguishable from the block's own
 * closing tag and truncates it.
 */
final class WikiSectionEditLinkInjector
{
    public function inject(string $html, string $editUrl): string
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div>'.$html.'</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED
        );
        libxml_clear_errors();

        $headingTags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
        $headings = [];

        // getElementsByTagName('*') walks in document order, unlike
        // collecting per tag name and merging — needed since headings of
        // different levels must still be numbered in the order they
        // actually appear on the page.
        foreach ($document->getElementsByTagName('*') as $element) {
            if (in_array($element->tagName, $headingTags, true)) {
                $headings[] = $element;
            }
        }

        if ($headings === []) {
            return $html;
        }

        foreach ($headings as $index => $heading) {
            $link = $document->createElement('a');
            $link->setAttribute('href', "{$editUrl}?section=".($index + 1));
            $link->setAttribute('class', 'section-edit ml-2 text-xs font-normal text-indigo-600 no-underline hover:underline');
            $link->textContent = '編集';
            $heading->appendChild($link);
        }

        $wrapper = $document->getElementsByTagName('div')->item(0);
        $inner = '';

        foreach (iterator_to_array($wrapper->childNodes) as $child) {
            $inner .= $document->saveHTML($child);
        }

        return $inner;
    }
}
