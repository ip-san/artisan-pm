<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use App\Models\Project;
use App\Models\WikiPage;
use App\Models\WikiRedirect;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Parses "[[Page Title]]" / "[[Page Title|Display text]]" into a link to
 * that wiki page (or, if no page with that title exists yet, to its
 * creation form pre-filled with the title).
 *
 * Registered directly (not through the generic Mention extension) at a
 * priority above CommonMark's core OpenBracketParser (20): both trigger on
 * a leading "[", and OpenBracketParser claims it first at the default
 * priority, silently swallowing "[[Title]]" as a failed link attempt
 * before a Mention-based parser ever gets a chance to match.
 */
final class WikiLinkInlineParser implements InlineParserInterface
{
    public function __construct(
        private readonly Project $project,
    ) {}

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::join(
            InlineParserMatch::string('[['),
            InlineParserMatch::regex('[^\]|\n]+(?:\|[^\]\n]+)?\]\]'),
        );
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        [, $rawIdentifier] = $inlineContext->getSubMatches();
        [$title, $display] = $this->parseIdentifier($rawIdentifier);

        $page = WikiPage::query()
            ->where('project_id', $this->project->id)
            ->where('title', $title)
            ->first();

        // A page renamed away from this title still resolves, via the
        // redirect WikiPageService::update() leaves behind — matches
        // Redmine's Wiki#find_page, which falls back to WikiRedirect the
        // same way when no page matches the title directly.
        if ($page === null) {
            $redirectsTo = WikiRedirect::query()
                ->where('project_id', $this->project->id)
                ->where('title', $title)
                ->value('redirects_to');

            if ($redirectsTo !== null) {
                $page = WikiPage::query()
                    ->where('project_id', $this->project->id)
                    ->where('title', $redirectsTo)
                    ->first();
            }
        }

        $url = $page !== null
            ? route('wiki.show', [$this->project, $page])
            : route('wiki.create', $this->project).'?'.http_build_query(['title' => $title]);

        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new Link($url, $display ?? $title));

        return true;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function parseIdentifier(string $identifier): array
    {
        $withoutClosingBrackets = substr($identifier, 0, -2);
        [$title, $display] = array_pad(explode('|', $withoutClosingBrackets, 2), 2, null);

        return [trim($title), $display !== null ? trim($display) : null];
    }
}
