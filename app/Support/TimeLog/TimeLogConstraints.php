<?php

declare(strict_types=1);

namespace App\Support\TimeLog;

use App\Models\Issue;
use App\Models\Setting;
use App\Models\TimeEntry;
use Illuminate\Validation\ValidationException;

/**
 * Redmine's `timelog_*` settings, enforced where TimeEntryService saves —
 * the one place every write path (web form, bulk edit, REST API, CSV
 * import, commit keywords) goes through, as Redmine enforces them in the
 * TimeEntry model. Defaults match Redmine's: nothing required, zero hours,
 * future dates and closed issues accepted, no daily maximum.
 */
final class TimeLogConstraints
{
    /** The fields `timelog_required_fields` may name. */
    public const array REQUIRABLE_FIELDS = ['issue_id' => '課題', 'comments' => 'コメント'];

    /**
     * @return array<int, string>
     */
    public static function requiredFields(): array
    {
        $fields = Setting::get('timelog_required_fields', []);

        return is_array($fields) ? array_values(array_intersect($fields, array_keys(self::REQUIRABLE_FIELDS))) : [];
    }

    public static function acceptsZeroHours(): bool
    {
        return (bool) Setting::get('timelog_accept_0_hours', true);
    }

    public static function maxHoursPerDay(): float
    {
        return max(0.0, (float) Setting::get('timelog_max_hours_per_day', 999));
    }

    public static function acceptsFutureDates(): bool
    {
        return (bool) Setting::get('timelog_accept_future_dates', true);
    }

    public static function acceptsClosedIssues(): bool
    {
        return (bool) Setting::get('timelog_accept_closed_issues', true);
    }

    /**
     * Checks `$attributes` (what is about to be saved) merged over
     * `$existing` (null when creating) and throws with per-field messages,
     * keyed like the form fields. As in Redmine's validate_time_entry, the
     * hours checks apply only when the hours change and the future-date
     * check only when the date changes, so an untouched old entry stays
     * editable; required fields and the closed-issue rule apply to every
     * save.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    public static function assertSatisfied(array $attributes, ?TimeEntry $existing = null): void
    {
        $value = fn (string $key) => array_key_exists($key, $attributes) ? $attributes[$key] : $existing?->{$key};
        $errors = [];

        foreach (self::requiredFields() as $field) {
            if (blank($value($field))) {
                $errors[$field][] = self::REQUIRABLE_FIELDS[$field].'を入力してください。';
            }
        }

        $hours = $value('hours');
        $hoursChanged = $existing === null || (array_key_exists('hours', $attributes) && (float) $attributes['hours'] !== (float) $existing->hours);

        if ($hours !== null && $hoursChanged) {
            if ((float) $hours === 0.0 && ! self::acceptsZeroHours()) {
                $errors['hours'][] = '0時間は記録できません。';
            }

            $max = self::maxHoursPerDay();

            if ($max > 0.0) {
                $logged = (float) TimeEntry::query()
                    ->where('user_id', $value('user_id'))
                    ->whereDate('spent_on', $value('spent_on'))
                    ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->getKey()))
                    ->sum('hours');

                if ($logged + (float) $hours > $max) {
                    $errors['hours'][] = sprintf('この日の工数の上限(%s時間)を超えます。すでに%s時間記録されています。', self::format($max), self::format($logged));
                }
            }
        }

        $spentOn = $value('spent_on');
        $dateChanged = $existing === null || (array_key_exists('spent_on', $attributes) && substr((string) $attributes['spent_on'], 0, 10) !== $existing->spent_on->toDateString());

        if ($spentOn !== null && $dateChanged && ! self::acceptsFutureDates() && substr((string) $spentOn, 0, 10) > now()->toDateString()) {
            $errors['spent_on'][] = '未来の日付には工数を記録できません。';
        }

        $issueId = $value('issue_id');

        if ($issueId !== null && ! self::acceptsClosedIssues()
            && Issue::query()->whereKey($issueId)->whereHas('status', fn ($status) => $status->where('is_closed', true))->exists()) {
            $errors['issue_id'][] = '終了した課題には工数を記録できません。';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private static function format(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    }
}
