<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use App\Models\Issue;
use App\Models\Project;
use App\Models\WikiPage;
use Closure;
use DOMDocument;
use DOMElement;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The macros a wiki page can call — Redmine's
 * Redmine::WikiFormatting::Macros. Each is a `{{name}}` or
 * `{{name(arg, key=value)}}` on a paragraph of its own; the paragraph is
 * swapped for the macro's HTML after the Markdown has rendered. A macro that
 * cannot work in its context (no page, unknown target, no permission) says so
 * in place rather than failing the page.
 *
 * `register()` is the extension point a plugin uses (Redmine's
 * `Macros.register`); the built-in ones are registered on first use.
 */
final class WikiMacros
{
    /** @var array<string, array{handler: Closure, description: string}>|null */
    private static ?array $macros = null;

    /**
     * @param  Closure(MacroCall): string  $handler  returns the macro's HTML
     */
    public static function register(string $name, Closure $handler, string $description): void
    {
        self::all();
        self::$macros[$name] = ['handler' => $handler, 'description' => $description];
    }

    /**
     * Drops every macro added with register(), for tests.
     */
    public static function reset(): void
    {
        self::$macros = null;
    }

    /**
     * @return array<string, array{handler: Closure, description: string}>
     */
    public static function all(): array
    {
        return self::$macros ??= self::builtIns();
    }

    /**
     * Whether $text calls a macro whose output can change without the text
     * changing (so a cached render would go stale).
     */
    public static function isDynamic(string $text): bool
    {
        return preg_match('/\{\{(?:child_pages|include|recent_pages|issue|thumbnail|macro_list)\b/', $text) === 1
            || preg_match('/\{\{(?!toc\b|collapse\b|hello_world\b)[a-z_]+/', $text) === 1;
    }

    /**
     * @param  MediaCollection<int, Media>|null  $attachments
     */
    public static function replaceIn(string $html, ?Project $project, ?WikiPage $page, ?MediaCollection $attachments): string
    {
        if (! str_contains($html, '{{')) {
            return $html;
        }

        $document = HtmlFragment::load($html);
        $changed = false;

        foreach (iterator_to_array($document->getElementsByTagName('p')) as $paragraph) {
            if (preg_match('/^\{\{([a-z_]+)(?:\((.*)\))?\}\}$/s', trim($paragraph->textContent), $matches) !== 1) {
                continue;
            }

            $macro = self::all()[$matches[1]] ?? null;

            if ($macro === null) {
                continue;
            }

            $call = new MacroCall($matches[1], self::splitArguments($matches[2] ?? ''), $project, $page, $attachments);
            $output = ($macro['handler'])($call);

            $replacement = HtmlFragment::load($output)->getElementsByTagName('div')->item(0);
            $anchor = $paragraph;

            foreach (iterator_to_array($replacement->childNodes) as $node) {
                $imported = $document->importNode($node, true);
                $paragraph->parentNode->insertBefore($imported, $anchor);
            }

            $paragraph->parentNode->removeChild($paragraph);
            $changed = true;
        }

        return $changed ? HtmlFragment::innerHtml($document) : $html;
    }

    /**
     * @return array<int, string>
     */
    private static function splitArguments(string $arguments): array
    {
        return $arguments === '' ? [] : array_map('trim', explode(',', $arguments));
    }

    /**
     * @return array<string, array{handler: Closure, description: string}>
     */
    private static function builtIns(): array
    {
        return [
            'hello_world' => ['description' => 'Sample macro that echoes its arguments.', 'handler' => fn (MacroCall $call) => '<p>Hello world! Arguments: '.e(implode(', ', $call->arguments)).'</p>'],
            'macro_list' => ['description' => 'Lists the available macros.', 'handler' => fn () => self::macroList()],
            'recent_pages' => ['description' => '{{recent_pages(N)}} — the N most recently updated wiki pages of the project (default 10).', 'handler' => self::recentPages(...)],
            'issue' => ['description' => '{{issue(123)}} — a link to an issue with its tracker, subject and status.', 'handler' => self::issue(...)],
            'thumbnail' => ['description' => '{{thumbnail(file.png, size=200, title=Text)}} — a thumbnail of an attached image, linked to the file.', 'handler' => self::thumbnail(...)],
            'child_pages' => ['description' => '{{child_pages(Page, depth=2, parent=1)}} — the child pages of this or the named page, nested to the given depth.', 'handler' => self::childPages(...)],
        ];
    }

    private static function macroList(): string
    {
        $items = '';

        foreach (self::all() as $name => $macro) {
            $items .= '<dt><code>{{'.e($name).'}}</code></dt><dd>'.e($macro['description']).'</dd>';
        }

        return '<dl class="macro-list"><dt><code>{{toc}}</code></dt><dd>'.e('The table of contents of the page.').'</dd>'
            .'<dt><code>{{collapse(Label)}}</code></dt><dd>'.e('A block that starts collapsed (closed by a }} line).').'</dd>'
            .'<dt><code>{{include(Page)}}</code></dt><dd>'.e('Another page (or project:Page) rendered in place.').'</dd>'
            .$items.'</dl>';
    }

    private static function error(string $message): string
    {
        return '<p class="macro-error">'.e($message).'</p>';
    }

    private static function recentPages(MacroCall $call): string
    {
        if ($call->project === null) {
            return self::error('{{recent_pages}} はプロジェクトのWikiでのみ使えます。');
        }

        $limit = max(1, min(100, (int) ($call->positional()[0] ?? 10)));

        $pages = $call->project->wikiPages()->with('project')->orderByDesc('updated_at')->get()
            ->filter(fn (WikiPage $candidate) => auth()->user()?->can('view', $candidate) ?? $candidate->project->is_public)
            ->take($limit);

        $items = $pages->map(fn (WikiPage $candidate) => '<li><a href="'.e(route('wiki.show', [$call->project, $candidate])).'">'.e($candidate->title).'</a></li>')->implode('');

        return '<ul class="recent-pages">'.$items.'</ul>';
    }

    private static function issue(MacroCall $call): string
    {
        $id = (int) ltrim($call->positional()[0] ?? '', '#');
        $issue = $id > 0 ? Issue::query()->with(['project', 'tracker', 'status'])->find($id) : null;

        if ($issue === null || ! (auth()->user()?->can('view', $issue) ?? false)) {
            return self::error("課題 #{$id} が見つかりません。");
        }

        $options = $call->options();
        $label = ($options['tracker'] ?? 'true') === 'false' ? '' : $issue->tracker->name.' ';
        $label .= '#'.$issue->id;

        if (($options['subject'] ?? 'true') !== 'false') {
            $label .= ': '.$issue->subject;
        }

        $status = ($options['status'] ?? 'true') === 'false' ? '' : ' ('.e($issue->status->name).')';

        return '<p class="issue-macro"><a href="'.e(route('issues.show', [$issue->project, $issue])).'">'.e($label).'</a>'.$status.'</p>';
    }

    private static function thumbnail(MacroCall $call): string
    {
        $filename = $call->positional()[0] ?? '';
        $media = $call->attachments?->sortByDesc('created_at')->first(fn (Media $candidate) => strcasecmp($candidate->file_name, $filename) === 0);

        if ($media === null) {
            return self::error("添付ファイル「{$filename}」が見つかりません。");
        }

        $options = $call->options();
        $size = max(16, min(1000, (int) ($options['size'] ?? 100)));
        $title = $options['title'] ?? $media->file_name;
        $source = $media->hasGeneratedConversion('thumb') ? route('attachments.thumb', $media) : route('attachments.show', $media);

        return '<p class="thumbnail"><a href="'.e(route('attachments.show', $media)).'"><img src="'.e($source).'" alt="'.e($title).'" title="'.e($title).'" style="max-width: '.$size.'px; max-height: '.$size.'px" loading="lazy"></a></p>';
    }

    private static function childPages(MacroCall $call): string
    {
        if ($call->project === null) {
            return '<p>{{child_pages}}</p>';
        }

        $options = $call->options();
        $depth = isset($options['depth']) ? max(1, (int) $options['depth']) : 1;
        $title = $call->positional()[0] ?? null;
        $root = $title !== null ? $call->project->wikiPages()->where('title', $title)->first() : $call->page;

        if ($root === null) {
            return $title !== null ? self::error("ページ「{$title}」が見つかりません。") : '<p>{{child_pages}}</p>';
        }

        $html = '';

        if (($options['parent'] ?? '0') === '1' || ($options['parent'] ?? '') === 'true') {
            $html .= '<p class="child-pages-parent"><a href="'.e(route('wiki.show', [$call->project, $root])).'">'.e($root->title).'</a></p>';
        }

        return $html.self::childList($call->project, $root, $depth);
    }

    private static function childList(Project $project, WikiPage $page, int $depth): string
    {
        $items = '';

        foreach ($page->children()->orderBy('title')->get() as $child) {
            $items .= '<li><a href="'.e(route('wiki.show', [$project, $child])).'">'.e($child->title).'</a>'
                .($depth > 1 ? self::childList($project, $child, $depth - 1) : '').'</li>';
        }

        return '<ul class="child-pages">'.$items.'</ul>';
    }
}
