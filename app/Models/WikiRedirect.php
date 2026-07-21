<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WikiRedirectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stale wiki page title that now points at its page's current title —
 * matches Redmine's WikiRedirect (one per Wiki there; scoped to Project
 * here, since this app has no separate Wiki model). Created whenever a
 * page is renamed, so old "[[Old Title]]" references still resolve
 * instead of rendering as a broken/create-new-page link — see
 * WikiLinkInlineParser, which is where the actual lookup fallback lives.
 */
#[Fillable(['project_id', 'title', 'redirects_to'])]
final class WikiRedirect extends Model
{
    /** @use HasFactory<WikiRedirectFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
