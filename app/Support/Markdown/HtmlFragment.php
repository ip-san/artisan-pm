<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use DOMDocument;

/**
 * Shared load/serialize plumbing for post-processing a rendered HTML
 * fragment with DOMDocument — used by WikiMarkdownRenderer (inline
 * attachment images) and WikiSectionEditLinkInjector (section edit
 * links). The fragment is wrapped in a <div> with a UTF-8 XML prologue
 * so libxml neither guesses the encoding nor injects html/body tags,
 * and serialized back as the wrapper's inner HTML.
 */
final class HtmlFragment
{
    public static function load(string $html): DOMDocument
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div>'.$html.'</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED
        );
        libxml_clear_errors();

        return $document;
    }

    public static function innerHtml(DOMDocument $document): string
    {
        $wrapper = $document->getElementsByTagName('div')->item(0);
        $inner = '';

        foreach (iterator_to_array($wrapper->childNodes) as $child) {
            $inner .= $document->saveHTML($child);
        }

        return $inner;
    }
}
