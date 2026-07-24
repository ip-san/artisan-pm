<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Support\Search\SearchResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The first API resource in this app wrapping a non-Eloquent value object
 * rather than a model — App\Support\Search\SearchResult is a plain
 * readonly DTO with no `id`/`project_id` (only type/title/url/excerpt/
 * updatedAt), so neither can be exposed here without extending that DTO,
 * which every one of SearchService's seven per-type builder methods would
 * need updating for — out of scope for wrapping the existing service.
 * `url` already carries an absolute deep link to the underlying record.
 * Flat fields, matching this app's own resource convention, rather than
 * Redmine's `event_*`-prefixed field names.
 *
 * @property SearchResult $resource
 */
final class SearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $result = $this->resource;

        return [
            'type' => $result->type,
            'title' => $result->title,
            'url' => $result->url,
            'description' => $result->excerpt,
            'updated_at' => $result->updatedAt->toIso8601String(),
        ];
    }
}
