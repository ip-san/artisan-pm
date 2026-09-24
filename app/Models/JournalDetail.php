<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomFieldFormat;
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
     * A recorded value as shown: start and due dates and date custom field
     * values in the date format (Redmine's show_detail uses format_date and
     * format_value), anything else as stored.
     */
    public function displayValue(?string $value): ?string
    {
        if ($value !== null && ($this->recordsIssueDate() || $this->recordsDateCustomField())
            && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return DateTimes::date(substr($value, 0, 10));
        }

        return $value;
    }

    private function recordsIssueDate(): bool
    {
        return $this->property === 'attr' && in_array($this->prop_key, ['start_date', 'due_date'], true);
    }

    private function recordsDateCustomField(): bool
    {
        if ($this->property !== 'cf') {
            return false;
        }

        // Looked up once per request or job (a scoped binding), not per detail.
        $key = 'journal-details.date-custom-field-ids';

        if (! app()->bound($key)) {
            app()->scoped($key, fn (): array => CustomField::query()->where('field_format', CustomFieldFormat::Date)->pluck('id')->flip()->all());
        }

        return array_key_exists((int) $this->prop_key, app($key));
    }
}
