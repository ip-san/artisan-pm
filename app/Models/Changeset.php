<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Markdown\WikiMarkdownRenderer;
use Database\Factories\ChangesetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

#[Fillable(['repository_id', 'revision', 'committer', 'committed_on', 'comments'])]
final class Changeset extends Model
{
    /** @use HasFactory<ChangesetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'committed_on' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * @return HasMany<ChangesetFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ChangesetFile::class);
    }

    /**
     * @return BelongsToMany<Issue, $this>
     */
    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class);
    }

    /**
     * Revision, shortened the way Git's own porcelain commands display it.
     */
    public function shortRevision(): string
    {
        return substr($this->revision, 0, 8);
    }

    /**
     * The commit message as HTML: Markdown (with `#123` issue links) when
     * the commit_logs_formatting setting is on, its Redmine default, else
     * escaped text with its line breaks kept. `$firstLineOnly` gives the
     * list view's one-line summary.
     */
    public function commentsHtml(bool $firstLineOnly = false): HtmlString
    {
        $text = trim((string) $this->comments);

        if ($firstLineOnly) {
            $text = Str::limit(trim(Str::before($text, "\n")), 120);
        }

        if (! (bool) Setting::get('commit_logs_formatting', true)) {
            return new HtmlString('<span class="whitespace-pre-line">'.e($text).'</span>');
        }

        return new HtmlString(app(WikiMarkdownRenderer::class)->render($text, $this->loadMissing('repository.project')->repository->project));
    }
}
