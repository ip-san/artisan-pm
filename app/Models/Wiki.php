<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WikiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's wiki settings — currently just `start_page` — matches
 * Redmine's Wiki model. One row per project, created lazily on first need
 * (see Project::wikiOrCreate()) rather than at project creation, since
 * nothing in this app currently requires a Wiki row to exist before then.
 * `start_page` is a plain string decoupled from any WikiPage actually
 * existing with that title, matching Redmine's own Wiki#find_page, which
 * falls back to a "new page" stub when it doesn't (see the `wiki.index`
 * route, which redirects to that stub's creation form in that case).
 */
#[Fillable(['project_id', 'start_page'])]
final class Wiki extends Model
{
    /** @use HasFactory<WikiFactory> */
    use HasFactory;

    protected $attributes = [
        'start_page' => 'Wiki',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function startPage(): ?WikiPage
    {
        return WikiPage::query()
            ->where('project_id', $this->project_id)
            ->where('title', $this->start_page)
            ->first();
    }
}
