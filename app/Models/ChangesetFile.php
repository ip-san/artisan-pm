<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChangesetFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['changeset_id', 'path', 'action', 'from_path'])]
final class ChangesetFile extends Model
{
    /** @use HasFactory<ChangesetFileFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Changeset, $this>
     */
    public function changeset(): BelongsTo
    {
        return $this->belongsTo(Changeset::class);
    }
}
