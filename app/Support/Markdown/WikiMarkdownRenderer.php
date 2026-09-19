<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Setting;
use App\Models\WikiPage;
use DOMDocument;
use DOMElement;
use DOMXPath;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Mention\Mention;
use League\CommonMark\Extension\Mention\MentionExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
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
 *
 * A fourth macro, `{{include(Page Title)}}`, splices another wiki
 * page's own rendered content inline — same pre-pass/recursive-render/
 * DOM-splice shape as {{collapse}}, since the included page's content
 * is itself unrendered Markdown too. Deliberately narrower than
 * Redmine's own `{{include(project:Page)}}` cross-project form: this
 * only ever resolves a page in the *same* project, matching the
 * same-project scope `[[Page]]` links and {{child_pages}} already
 * have — which conveniently also sidesteps Redmine's separate
 * view_wiki_pages permission check on the target project, since being
 * able to render this page at all already implies that permission on
 * the one project in play. A page that doesn't exist (including any
 * `project:Page` form, which never matches a real title) renders a
 * visible inline error rather than silently vanishing, matching
 * Redmine's own inline-macro-error behavior. Circular includes (A
 * includes B includes A) are guarded by threading the chain of already-
 * included page ids through the recursive render() calls.
 */
final class WikiMarkdownRenderer
{
    private const CACHE_MIN_BYTES = 2048;

    private const CACHE_TTL_SECONDS = 3600;

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
     * @param  Project|null  $project  the project $text is scoped to, needed
     *                                 to resolve [[Page]] links and
     *                                 {{include}}/{{child_pages}} macros —
     *                                 left as literal text when this is null
     *                                 (e.g. rendering the site-wide
     *                                 welcome_text setting, which isn't
     *                                 scoped to any single project).
     * @param  WikiPage|null  $page  the page $text belongs to, needed only to
     *                               resolve {{child_pages}} — left as literal
     *                               text when this is null (e.g. rendering an
     *                               issue description, not a wiki page).
     * @param  array<int, int>  $includedPageIds  internal — the chain of
     *                                            WikiPage ids already being
     *                                            rendered via {{include}} in
     *                                            this call tree, so a cycle
     *                                            can be detected. Callers
     *                                            outside this class should
     *                                            never pass this.
     */
    public function render(string $text, ?Project $project = null, ?MediaCollection $attachments = null, ?WikiPage $page = null, array $includedPageIds = []): string
    {
        $cacheKey = $this->cacheKeyFor($text, $project, $attachments, $page, $includedPageIds);

        if ($cacheKey === null) {
            return $this->renderMarkdown($text, $project, $attachments, $page, $includedPageIds);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, fn () => $this->renderMarkdown($text, $project, $attachments, $page, $includedPageIds));
    }

    /**
     * Redmine's cache_formatted_text (config/settings.yml, default off):
     * only texts over 2 KB are worth caching. This renderer's output also
     * depends on data outside $text, so anything whose result can go stale
     * without the text changing is never cached — a page pulling in
     * another via {{include}} or listing children via {{child_pages}} —
     * and a stored result expires after CACHE_TTL_SECONDS. Left, as in
     * Redmine, is the small window where a "#123" or "[[Page]]" reference
     * renders differently after the target is created or deleted.
     *
     * @param  MediaCollection<int, Media>|null  $attachments
     * @param  array<int, int>  $includedPageIds
     */
    private function cacheKeyFor(string $text, ?Project $project, ?MediaCollection $attachments, ?WikiPage $page, array $includedPageIds): ?string
    {
        if ($includedPageIds !== []
            || strlen($text) <= self::CACHE_MIN_BYTES
            || WikiMacros::isDynamic($text)
            || ! Setting::get('cache_formatted_text', false)
        ) {
            return null;
        }

        $attachmentSignature = $attachments === null
            ? ''
            : $attachments->map(fn (Media $media) => $media->id.':'.$media->file_name.':'.$media->updated_at?->getTimestamp())->implode(',');

        return 'formatted_text:'.hash('sha256', implode('|', [
            $project?->id ?? 0,
            $page?->id ?? 0,
            $attachmentSignature,
            $text,
        ]));
    }

    /**
     * The uncached renderer; also the entry point for the recursive
     * {{collapse}} / {{include}} calls, which are never cached on their own.
     *
     * @param  MediaCollection<int, Media>|null  $attachments
     * @param  array<int, int>  $includedPageIds
     */
    private function renderMarkdown(string $text, ?Project $project = null, ?MediaCollection $attachments = null, ?WikiPage $page = null, array $includedPageIds = []): string
    {
        [$text, $collapseBlocks] = $this->extractCollapseBlocks($text, $project, $attachments, $page, $includedPageIds);
        [$text, $includeBlocks] = $this->extractIncludeMacros($text, $project, $includedPageIds);

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

        // Left unregistered when there's no project to resolve [[Page]]
        // against — matches the {{child_pages}}/{{include}} macros' own
        // "left as literal text" fallback below, rather than pointing the
        // link somewhere arbitrary.
        if ($project !== null) {
            $environment->addInlineParser(new WikiLinkInlineParser($project), 25);
        }

        $html = (new MarkdownConverter($environment))->convert($text)->getContent();
        $html = $this->replaceCollapseBlocks($html, $collapseBlocks);
        $html = $this->replaceIncludeMacros($html, $includeBlocks);
        $html = WikiMacros::replaceIn($html, $project, $page, $attachments);

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
     * Blocks may nest: the body is found by scanning line by line and
     * counting opening `{{collapse` lines against closing `}}` lines, so an
     * inner block's `}}` no longer ends the outer one; the inner block is
     * rendered by the recursive call on the outer body. A block that is
     * never closed is left as literal text.
     *
     * @param  MediaCollection<int, Media>|null  $attachments
     * @param  array<int, int>  $includedPageIds
     * @return array{0: string, 1: array<string, array{label: string, body: string}>}
     */
    private function extractCollapseBlocks(string $text, ?Project $project, ?MediaCollection $attachments, ?WikiPage $page, array $includedPageIds): array
    {
        $blocks = [];
        $lines = explode("\n", $text);
        $output = [];
        $count = count($lines);
        $index = 0;

        while ($index < $count) {
            $opening = $this->collapseOpening($lines[$index]);
            $closingIndex = $opening === null ? null : $this->collapseClosingIndex($lines, $index);

            if ($opening === null || $closingIndex === null) {
                $output[] = $lines[$index];
                $index++;

                continue;
            }

            $placeholder = 'COLLAPSE-MACRO-PLACEHOLDER-'.count($blocks);

            $blocks[$placeholder] = [
                'label' => $opening !== '' ? $opening : '表示',
                'body' => $this->renderMarkdown(
                    implode("\n", array_slice($lines, $index + 1, $closingIndex - $index - 1)),
                    $project,
                    $attachments,
                    $page,
                    $includedPageIds,
                ),
            ];

            // Blank lines around it keep the placeholder a paragraph of its
            // own even when the block directly follows or precedes text —
            // replaceCollapseBlocks() only swaps whole-paragraph matches.
            array_push($output, '', $placeholder, '');
            $index = $closingIndex + 1;
        }

        $text = implode("\n", $output);

        return [$text, $blocks];
    }

    /**
     * The trimmed label of a `{{collapse}}` / `{{collapse(Label)}}` opening
     * line, or null when the line is not one.
     */
    private function collapseOpening(string $line): ?string
    {
        return preg_match('/^\{\{collapse(?:\(([^)]*)\))?[ \t]*\r?$/', $line, $matches) === 1
            ? trim($matches[1] ?? '')
            : null;
    }

    /**
     * Index of the `}}` line that closes the block opened at $openIndex,
     * counting nested openings, or null when it is never closed.
     *
     * @param  array<int, string>  $lines
     */
    private function collapseClosingIndex(array $lines, int $openIndex): ?int
    {
        $depth = 1;

        for ($index = $openIndex + 1, $count = count($lines); $index < $count; $index++) {
            if ($this->collapseOpening($lines[$index]) !== null) {
                $depth++;
            } elseif (preg_match('/^\}\}[ \t]*\r?$/', $lines[$index]) === 1) {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
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
     * A `{{include(Page Title)}}` line on its own is replaced with a
     * placeholder the same way {{collapse}} is — see extractCollapseBlocks()
     * for why a pure post-render DOM step isn't enough here. Left as
     * literal text when there's no $project to resolve it against (e.g.
     * rendering the site-wide welcome_text setting).
     *
     * @param  array<int, int>  $includedPageIds
     * @return array{0: string, 1: array<string, string>}
     */
    private function extractIncludeMacros(string $text, ?Project $project, array $includedPageIds): array
    {
        if ($project === null) {
            return [$text, []];
        }

        $blocks = [];

        $text = preg_replace_callback(
            '/^\{\{include\(([^)]+)\)\}\}[ \t]*$/m',
            function (array $matches) use (&$blocks, $project, $includedPageIds) {
                $placeholder = 'INCLUDE-MACRO-PLACEHOLDER-'.count($blocks);
                $blocks[$placeholder] = $this->renderIncludedPage(trim($matches[1]), $project, $includedPageIds);

                return $placeholder;
            },
            $text,
        ) ?? $text;

        return [$text, $blocks];
    }

    /**
     * @param  array<int, int>  $includedPageIds
     */
    private function renderIncludedPage(string $title, Project $project, array $includedPageIds): string
    {
        // `project:Page` reaches into another project (Redmine's
        // {{include(project:Page)}}); that page's own view permission decides,
        // since being able to read this one says nothing about it.
        $targetProject = $project;

        if (str_contains($title, ':')) {
            [$identifier, $pageTitle] = array_map('trim', explode(':', $title, 2));
            $other = Project::query()->where('identifier', $identifier)->first();

            if ($other !== null) {
                $targetProject = $other;
                $title = $pageTitle;
            }
        }

        $target = $targetProject->wikiPages()->where('title', $title)->first();

        // A page the reader may not see is reported as missing, so its
        // existence is not revealed either.
        if ($target === null || ($targetProject->isNot($project) && ! Gate::forUser(auth()->user())->allows('view', $target))) {
            return '<p>'.e("ページ「{$title}」が見つかりません。").'</p>';
        }

        if (in_array($target->id, $includedPageIds, true)) {
            return '<p>'.e("「{$title}」の循環インクルードが検出されました。").'</p>';
        }

        $html = $this->renderMarkdown(
            $target->currentVersion === null ? '' : $target->currentVersion->text,
            $targetProject,
            $target->attachments(),
            $target,
            [...$includedPageIds, $target->id],
        );

        // Heading ids would otherwise collide with the including page's
        // own headings (both {{toc}} and any manual #fragment link only
        // make sense pointing at one place) — matches Redmine's own
        // :headings => false for included content.
        return $this->stripHeadingIds($html);
    }

    private function stripHeadingIds(string $html): string
    {
        if (! str_contains($html, '<h')) {
            return $html;
        }

        $document = HtmlFragment::load($html);
        $headings = (new DOMXPath($document))->query('//h1|//h2|//h3|//h4|//h5|//h6');

        if ($headings === false || $headings->length === 0) {
            return $html;
        }

        foreach ($headings as $heading) {
            /** @var DOMElement $heading */
            $heading->removeAttribute('id');
        }

        return HtmlFragment::innerHtml($document);
    }

    /**
     * @param  array<string, string>  $blocks
     */
    private function replaceIncludeMacros(string $html, array $blocks): string
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

            $wrapper = HtmlFragment::load($blocks[$placeholder])->getElementsByTagName('div')->item(0);

            foreach (iterator_to_array($wrapper->childNodes) as $child) {
                $paragraph->parentNode->insertBefore($document->importNode($child, true), $paragraph);
            }

            $paragraph->parentNode->removeChild($paragraph);
            $changed = true;
        }

        return $changed ? HtmlFragment::innerHtml($document) : $html;
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
