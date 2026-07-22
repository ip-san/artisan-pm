<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use App\Models\Issue;
use App\Models\Project;
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
     */
    public function render(string $text, Project $project, ?MediaCollection $attachments = null): string
    {
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

        if ($attachments === null || $attachments->isEmpty()) {
            return $html;
        }

        return $this->resolveInlineAttachmentImages($html, $attachments);
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
