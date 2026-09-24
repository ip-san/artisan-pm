<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Format\DateTimes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['journal_id', 'property', 'prop_key', 'old_value', 'new_value'])]
final class JournalDetail extends Model
{
    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * A recorded value as shown: start and due dates in the date format
     * (Redmine's show_detail uses format_date), anything else as stored.
     */
    public function displayValue(?string $value): ?string
    {
        if ($value !== null && $this->property === 'attr' && in_array($this->prop_key, ['start_date', 'due_date'], true)
            && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return DateTimes::date(substr($value, 0, 10));
        }

        return $value;
    }
}
