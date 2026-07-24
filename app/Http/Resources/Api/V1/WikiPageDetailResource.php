<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\WikiPage;
use App\Models\WikiPageVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Used for show/store/update responses — unlike the existing
 * WikiPageResource (webhook-payload-only, metadata only), this includes
 * the version's text/comments, matching Redmine's show.api.rsb. Reused
 * for a specific historical version via the optional constructor arg
 * (GET /wiki/{wiki_page}?version=N), falling back to the current version
 * otherwise.
 *
 * @property WikiPage $resource
 */
final class WikiPageDetailResource extends JsonResource
{
    public function __construct(WikiPage $resource, private readonly ?WikiPageVersion $version = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $wikiPage = $this->resource;
        $version = $this->version ?? $wikiPage->currentVersion;

        return [
            'id' => $wikiPage->id,
            'project_id' => $wikiPage->project_id,
            'parent_id' => $wikiPage->parent_id,
            'title' => $wikiPage->title,
            'is_protected' => $wikiPage->is_protected,
            'version' => $version?->version,
            'author_id' => $version?->author_id,
            'text' => $version?->text,
            'comments' => $version?->comments,
            'created_at' => $wikiPage->created_at->toIso8601String(),
            'updated_at' => $wikiPage->updated_at->toIso8601String(),
        ];
    }
}
