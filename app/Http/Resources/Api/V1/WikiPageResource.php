<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\WikiPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property WikiPage $resource
 */
final class WikiPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $wikiPage = $this->resource;

        return [
            'id' => $wikiPage->id,
            'project_id' => $wikiPage->project_id,
            'parent_id' => $wikiPage->parent_id,
            'title' => $wikiPage->title,
            'is_protected' => $wikiPage->is_protected,
            'version' => $wikiPage->currentVersion?->version,
            'author_id' => $wikiPage->currentVersion?->author_id,
            'created_at' => $wikiPage->created_at->toIso8601String(),
            'updated_at' => $wikiPage->updated_at->toIso8601String(),
        ];
    }
}
