<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WikiPageVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['wiki_page_id', 'author_id', 'text', 'comments', 'version'])]
final class WikiPageVersion extends Model
{
    /** @use HasFactory<WikiPageVersionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<WikiPage, $this>
     */
    public function wikiPage(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
