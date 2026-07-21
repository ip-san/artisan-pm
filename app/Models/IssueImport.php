<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id', 'user_id', 'original_filename', 'file_path',
    'column_mapping', 'status', 'total_rows', 'processed_rows',
    'imported_count', 'failed_count', 'errors',
])]
final class IssueImport extends Model
{
    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'errors' => 'array',
            'status' => ImportStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progressPercent(): int
    {
        if ($this->total_rows === null || $this->total_rows === 0) {
            return $this->status->isFinished() ? 100 : 0;
        }

        return (int) round(min($this->processed_rows, $this->total_rows) / $this->total_rows * 100);
    }
}
