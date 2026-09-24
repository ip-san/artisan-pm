<?php

declare(strict_types=1);

namespace App\Support\TimeLog;

use App\Models\Issue;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Format\DateTimes;
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
    /** The fields `timelog_required_fields` may name (keys); use requirableFieldLabels() to show them. */
    public const array REQUIRABLE_FIELDS = ['issue_id' => '課題', 'comments' => 'コメント'];

    /**
     * The translated label of each requirable field, keyed like REQUIRABLE_FIELDS.
     *
     * @return array<string, string>
     */
    public static function requirableFieldLabels(): array
    {
        return [
            'issue_id' => __('課題'),
            'comments' => __('コメント'),
        ];
    }

    /** The "cannot be blank" message for a required field. */
    private static function requiredFieldMessage(string $field): string
    {
        return match ($field) {
            'issue_id' => __('課題を入力してください。'),
            default => __('コメントを入力してください。'),
        };
    }

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
                $errors[$field][] = self::requiredFieldMessage($field);
            }
        }

        $hours = $value('hours');
        $hoursChanged = $existing === null || (array_key_exists('hours', $attributes) && (float) $attributes['hours'] !== (float) $existing->hours);

        if ($hours !== null && $hoursChanged) {
            if ((float) $hours === 0.0 && ! self::acceptsZeroHours()) {
                $errors['hours'][] = __('0時間は記録できません。');
            }

            $max = self::maxHoursPerDay();

            if ($max > 0.0) {
                $logged = (float) TimeEntry::query()
                    ->where('user_id', $value('user_id'))
                    ->whereDate('spent_on', $value('spent_on'))
                    ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->getKey()))
                    ->sum('hours');

                if ($logged + (float) $hours > $max) {
                    $errors['hours'][] = __('この日の工数の上限(:max時間)を超えます。すでに:logged時間記録されています。', ['max' => self::format($max), 'logged' => self::format($logged)]);
                }
            }
        }

        $spentOn = $value('spent_on');
        $dateChanged = $existing === null || (array_key_exists('spent_on', $attributes) && substr((string) $attributes['spent_on'], 0, 10) !== $existing->spent_on->toDateString());

        if ($spentOn !== null && $dateChanged && ! self::acceptsFutureDates() && substr((string) $spentOn, 0, 10) > DateTimes::today(User::query()->find($value('user_id')))->toDateString()) {
            $errors['spent_on'][] = __('未来の日付には工数を記録できません。');
        }

        $issueId = $value('issue_id');

        if ($issueId !== null && ! self::acceptsClosedIssues()
            && Issue::query()->whereKey($issueId)->whereHas('status', fn ($status) => $status->where('is_closed', true))->exists()) {
            $errors['issue_id'][] = __('終了した課題には工数を記録できません。');
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
