<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use App\Models\Issue;
use App\Models\Project;
use App\Models\WikiPage;
use DOMDocument;
use DOMElement;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Mention\Mention;
use League\CommonMark\Extension\Mention\MentionExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Renders wiki/issue Markdown text to HTML, extending GitHub-Flavored
 * Markdown with two Redmine-style reference syntaxes: "#123" links to an
 * issue by id (via the Mention extension), and "[[Page Title]]" /
 * "[[Page Title|Display]]" links to another wiki page in the same project
 * (via WikiLinkInlineParser — see that class for why it can't also use the
 * Mention extension), plus the first slice of Redmine's macro engine: a
 * `{{toc}}` line on its own is replaced with a nested list of the
 * document's headings (league/commonmark's own TableOfContentsExtension —
 * already a transitive dependency, no new package). It depends on
 * HeadingPermalinkExtension for heading ids/anchors; `insert: after` is
 * required for the permalink anchor node to actually attach to the
 * heading tree (TableOfContentsGenerator walks that node to find its
 * heading), so `symbol`/`title` are left empty to keep it an invisible,
 * zero-width anchor rather than a visible permalink icon on every
 * heading.
 *
 * Raw HTML is escaped (not passed through) since wiki/issue text is
 * arbitrary input from any project member with edit access, not trusted
 * markup — see https://commonmark.thephpleague.com/security/.
 *
 * A second macro, `{{child_pages}}`, is hand-rolled rather than a
 * CommonMark extension (unlike {{toc}}, no third-party extension knows
 * about this app's WikiPage model) — it's swapped in as a DOM
 * post-process on the already-rendered HTML, the same technique
 * resolveInlineAttachmentImages() already uses below, rather than a
 * raw-text pre-pass: html_input=escape means any HTML injected into
 * $text before CommonMark runs would just come back out re-escaped as
 * visible text, not real markup.
 *
 * A third macro, `{{collapse}}` / `{{collapse(Label)}}`, wraps a
 * multi-line block of its own Markdown in a collapsible section —
 * unlike {{toc}}/{{child_pages}}, its body is itself unrendered
 * Markdown, so it can't be resolved as a pure HTML post-process; it's
 * extracted from $text before CommonMark ever sees it, replaced with a
 * plain-text placeholder paragraph, and swapped back in afterward the
 * same DOM-post-process way (rendering the extracted body recursively
 * through render() itself, so nested macros/attachments/links inside a
 * collapsed block work exactly as they would anywhere else). Redmine's
 * own version renders two jQuery-toggled links plus a hidden div; this
 * uses a native `<details>/<summary>` element instead — no JS needed
 * for the same collapsed-by-default, click-to-expand behavior.
 */
final class WikiMarkdownRenderer
{
    /**
     * Image extensions eligible for inline resolution — matches Redmine's
     * InlineAttachmentsScrubber exactly (notably no .svg, for the same XSS
     * reasons Redmine avoids it there).
     */
    private const IMAGE_EXTENSIONS = 'avif|bmp|gif|jpe?g|png|webp';

    /**
     * @param  MediaCollection<int, Media>|null  $attachments  when given, a
     *                                                         standard Markdown image whose target is a bare filename (no
     *                                                         path or scheme — e.g. `![](screenshot.png)`) and matches one of
     *                                                         these by name is rewired to that attachment's URL, so it embeds
     *                                                         inline instead of rendering as a broken image. Matches
     *                                                         Redmine's `attachment:file.png` inline-image convention
     *                                                         (InlineAttachmentsScrubber), which resolves against the same
     *                                                         object's own attachments rather than a global namespace.
     * @param  WikiPage|null  $page  the page $text belongs to, needed only to
     *                               resolve {{child_pages}} — left as literal
     *                               text when this is null (e.g. rendering an
     *                               issue description, not a wiki page).
     */
    public function render(string $text, Project $project, ?MediaCollection $attachments = null, ?WikiPage $page = null): string
    {
        [$text, $collapseBlocks] = $this->extractCollapseBlocks($text, $project, $attachments, $page);

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'mentions' => [
                'issue' => [
                    'prefix' => '#',
                    'pattern' => '\d+',
                    'generator' => function (Mention $mention) {
                        $issue = Issue::query()
                            ->select(['id', 'project_id', 'subject'])
                            ->with('project:id,identifier')
                            ->find((int) $mention->getIdentifier());

                        if ($issue === null) {
                            return null;
                        }

                        $mention->setUrl(route('issues.show', [$issue->project, $issue]));

                        return $mention;
                    },
                ],
            ],
            'heading_permalink' => [
                'html_class' => 'heading-permalink',
                'insert' => 'after',
                'id_prefix' => '',
                'fragment_prefix' => '',
                'apply_id_to_heading' => true,
                'symbol' => '',
                'title' => '',
            ],
            'table_of_contents' => [
                'position' => 'placeholder',
                'style' => 'bullet',
                'placeholder' => '{{toc}}',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new MentionExtension);
        $environment->addExtension(new HeadingPermalinkExtension);
        $environment->addExtension(new TableOfContentsExtension);
        $environment->addInlineParser(new WikiLinkInlineParser($project), 25);

        $html = (new MarkdownConverter($environment))->convert($text)->getContent();
        $html = $this->replaceCollapseBlocks($html, $collapseBlocks);
        $html = $this->replaceChildPagesMacro($html, $page, $project);

        if ($attachments === null || $attachments->isEmpty()) {
            return $html;
        }

        return $this->resolveInlineAttachmentImages($html, $attachments);
    }

    /**
     * Finds every `{{collapse}}` / `{{collapse(Label)}}` block — the
     * macro name and optional parenthesized label on their own line,
     * followed by the body, closed by a `}}` on its own line — and
     * replaces each one with a plain-text placeholder paragraph that
     * survives the CommonMark pass unmangled (an HTML placeholder would
     * just come back out re-escaped, same reasoning as {{child_pages}}).
     * The body is rendered recursively through render() itself right
     * here, not deferred to the post-process step, since by the time
     * replaceCollapseBlocks() runs the body's own Markdown needs to
     * already be HTML.
     *
     * A collapse block nested inside another isn't correctly supported:
     * the non-greedy body match stops at the first `}}` it finds, which
     * for nested blocks is the inner one's closing marker, not the
     * outer's — a regex can't balance same-name nested delimiters in one
     * pass. This degrades safely (the inner open tag is left as literal
     * text rather than crashing or mangling the rest of the page) rather
     * than nesting correctly; a real fix would need a proper scanner,
     * not a regex, and nested collapse blocks are enough of an edge case
     * that it's left as a known limitation.
     *
     * @param  MediaCollection<int, Media>|null  $attachments
     * @return array{0: string, 1: array<string, array{label: string, body: string}>}
     */
    private function extractCollapseBlocks(string $text, Project $project, ?MediaCollection $attachments, ?WikiPage $page): array
    {
        $blocks = [];

        $text = preg_replace_callback(
            '/^\{\{collapse(?:\(([^)]*)\))?[ \t]*\r?\n(.*?)\r?\n\}\}[ \t]*$/ms',
            function (array $matches) use (&$blocks, $project, $attachments, $page) {
                $label = trim($matches[1]);
                $placeholder = 'COLLAPSE-MACRO-PLACEHOLDER-'.count($blocks);

                $blocks[$placeholder] = [
                    'label' => $label !== '' ? $label : '表示',
                    'body' => $this->render($matches[2], $project, $attachments, $page),
                ];

                return $placeholder;
            },
            $text,
        ) ?? $text;

        return [$text, $blocks];
    }

    /**
     * @param  array<string, array{label: string, body: string}>  $blocks
     */
    private function replaceCollapseBlocks(string $html, array $blocks): string
    {
        if ($blocks === []) {
            return $html;
        }

        $document = HtmlFragment::load($html);
        $changed = false;

        foreach (iterator_to_array($document->getElementsByTagName('p')) as $paragraph) {
            $placeholder = trim($paragraph->textContent);

            if (! isset($blocks[$placeholder])) {
                continue;
            }

            $details = HtmlFragment::load(
                '<details><summary>'.e($blocks[$placeholder]['label']).'</summary>'.$blocks[$placeholder]['body'].'</details>'
            )->getElementsByTagName('details')->item(0);

            $paragraph->parentNode->replaceChild($document->importNode($details, true), $paragraph);
            $changed = true;
        }

        return $changed ? HtmlFragment::innerHtml($document) : $html;
    }

    /**
     * A `{{child_pages}}` line on its own is replaced with a flat,
     * alphabetically-ordered list of $page's own child pages — the same
     * ordering/flatness as the "子ページ" list wiki.show already renders
     * separately (see its `children()` computed property). Left
     * untouched as literal text when there's no $page (matches Redmine's
     * own "can be used from a wiki page only" restriction) or the macro
     * isn't alone on its own line — the same standalone-line requirement
     * {{toc}} already has.
     */
    private function replaceChildPagesMacro(string $html, ?WikiPage $page, Project $project): string
    {
        if ($page === null || ! str_contains($html, '{{child_pages}}')) {
            return $html;
        }

        $document = HtmlFragment::load($html);
        $changed = false;

        foreach (iterator_to_array($document->getElementsByTagName('p')) as $paragraph) {
            if (trim($paragraph->textContent) !== '{{child_pages}}') {
                continue;
            }

            $paragraph->parentNode->replaceChild($this->buildChildPagesList($document, $page, $project), $paragraph);
            $changed = true;
        }

        return $changed ? HtmlFragment::innerHtml($document) : $html;
    }

    private function buildChildPagesList(DOMDocument $document, WikiPage $page, Project $project): DOMElement
    {
        $list = $document->createElement('ul');
        $list->setAttribute('class', 'child-pages');

        foreach ($page->children()->orderBy('title')->get() as $child) {
            $link = $document->createElement('a');
            $link->setAttribute('href', route('wiki.show', [$project, $child]));
            $link->textContent = $child->title;

            $item = $document->createElement('li');
            $item->appendChild($link);
            $list->appendChild($item);
        }

        return $list;
    }

    /**
     * @param  MediaCollection<int, Media>  $attachments
     */
    private function resolveInlineAttachmentImages(string $html, MediaCollection $attachments): string
    {
        $document = HtmlFragment::load($html);

        // Newest first, so when multiple attachments share a filename the
        // most recently uploaded one wins — matches Redmine's own
        // InlineAttachmentsScrubber sort order.
        $byFilename = $attachments->sortByDesc('created_at');
        $changed = false;

        foreach (iterator_to_array($document->getElementsByTagName('img')) as $img) {
            /** @var DOMElement $img */
            $src = $img->getAttribute('src');

            if (preg_match('/^(?<filename>[^\/]+\.(?:'.self::IMAGE_EXTENSIONS.'))$/i', $src, $matches) !== 1) {
                continue;
            }

            $filename = rawurldecode($matches['filename']);
            $match = $byFilename->first(fn (Media $media) => strcasecmp($media->file_name, $filename) === 0);

            if ($match === null) {
                continue;
            }

            $img->setAttribute('src', route('attachments.show', $match));
            $img->setAttribute('loading', 'lazy');
            $changed = true;
        }

        if (! $changed) {
            return $html;
        }

        return HtmlFragment::innerHtml($document);
    }
}
