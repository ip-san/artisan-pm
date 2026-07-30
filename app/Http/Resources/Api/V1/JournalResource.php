<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Journal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Journal $resource
 */
final class JournalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $journal = $this->resource;

        return [
            'id' => $journal->id,
            'issue_id' => $journal->issue_id,
            'user_id' => $journal->user_id,
            'notes' => $journal->notes,
            'private_notes' => $journal->private_notes,
            'created_at' => $journal->created_at->toIso8601String(),
            'updated_at' => $journal->updated_at->toIso8601String(),
        ];
    }
}
