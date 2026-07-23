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
 *
 * `redirects_to_project_id` is null for an ordinary same-project rename
 * (the common case — resolved implicitly against `project_id`) and set
 * only when the page was moved to a different project, so a stale link
 * left behind in the old project can still resolve across projects —
 * matches Redmine's WikiRedirect#redirects_to_wiki_id, which likewise
 * defaults to the redirect's own wiki_id and is only overridden on a
 * cross-wiki move.
 */
#[Fillable(['project_id', 'title', 'redirects_to', 'redirects_to_project_id'])]
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

    /**
     * @return BelongsTo<Project, $this>
     */
    public function redirectsToProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'redirects_to_project_id');
    }
}
