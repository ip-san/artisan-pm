<?php

declare(strict_types=1);

namespace App\Support\Issues;

use App\Enums\VersionStatus;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Services\WorkflowService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Which issue fields a user may set and must fill, for an issue in a given
 * state (project, tracker, status): the workflow's per-role read-only and
 * required rules (WorkflowService::fieldRules()) and the fields the tracker
 * disables. Redmine's Issue#safe_attribute_names (read-only and disabled
 * fields are dropped from the input) and #validate_required_fields — every
 * path that takes issue fields from a user (the issue form, bulk edit and
 * context menu, REST, CSV import, incoming mail) goes through
 * IssueService::create()/update() with the rules enforced there.
 */
final class IssueFieldRules
{
    /**
     * The attributes a workflow rule or a tracker never drops here: they
     * decide which rules apply (Redmine assigns them before the others).
     *
     * @var array<int, string>
     */
    private const array STRUCTURAL_ATTRIBUTES = ['project_id', 'tracker_id', 'status_id'];

    /**
     * @param  array<string, 'required'|'read_only'>  $rules
     */
    private function __construct(
        private readonly Issue $issue,
        private readonly User $user,
        private readonly array $rules,
    ) {}

    /**
     * The rules for $issue as it stands — pass an unsaved issue (or a clone)
     * with the target project, tracker and status filled in to get the rules
     * a change would be checked against.
     */
    public static function for(Issue $issue, User $user): self
    {
        $rules = $issue->tracker_id !== null && $issue->status_id !== null && $issue->project_id !== null
            ? app(WorkflowService::class)->fieldRules($issue, $user)
            : [];

        return new self($issue, $user, $rules);
    }

    /**
     * Redmine's safe_attributes= for $user's input on $issue (a new issue
     * with its author set, or an existing one before the change): a tracker
     * the current rules make read-only is kept, then the fields read-only
     * under the resulting tracker and status, and those the tracker
     * disables, are dropped. A read-only assignee keeps a group assignee
     * too; read-only custom fields are dropped by id.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int|string, mixed>  $customFieldData  custom_field_id => raw input
     * @return array{0: array<string, mixed>, 1: array<int|string, mixed>, 2: self} the kept attributes and custom field values, and the rules of the resulting state
     */
    public static function filterInput(Issue $issue, array $attributes, array $customFieldData, User $user): array
    {
        if ($issue->exists && array_key_exists('tracker_id', $attributes) && self::for($issue, $user)->isReadOnly('tracker_id')) {
            unset($attributes['tracker_id']);
        }

        $target = self::targetState($issue, $attributes);
        $rules = self::for($target, $user);

        foreach (array_keys($attributes) as $attribute) {
            if (! in_array($attribute, self::STRUCTURAL_ATTRIBUTES, true) && ! $rules->isWritable($attribute)) {
                unset($attributes[$attribute]);
            }
        }

        if (array_key_exists('assigned_to_group_id', $attributes) && ! $rules->isWritable('assigned_to_id')) {
            unset($attributes['assigned_to_group_id']);
        }

        $customFieldData = collect($customFieldData)
            ->reject(fn (mixed $value, int|string $id): bool => $rules->isReadOnly("cf_{$id}"))
            ->all();

        return [$attributes, $customFieldData, $rules];
    }

    /**
     * $issue with the project, tracker and status of $attributes applied, on
     * a copy so the issue itself is untouched.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function targetState(Issue $issue, array $attributes): Issue
    {
        $target = clone $issue;

        foreach (self::STRUCTURAL_ATTRIBUTES as $attribute) {
            if (array_key_exists($attribute, $attributes) && $attributes[$attribute] !== null) {
                $target->setAttribute($attribute, (int) $attributes[$attribute]);
            }
        }

        if ($target->project_id !== null && $target->relationLoaded('project') && $target->project?->id !== $target->project_id) {
            $target->unsetRelation('project');
        }

        return $target->unsetRelation('tracker');
    }

    /**
     * @return array<string, 'required'|'read_only'>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function isReadOnly(string $field): bool
    {
        return ($this->rules[self::ruleKey($field)] ?? null) === 'read_only';
    }

    /**
     * Whether the tracker hides the core field (Redmine's
     * Tracker#disabled_core_fields).
     */
    public function isDisabled(string $field): bool
    {
        return $this->tracker()?->isCoreFieldDisabled(self::coreFieldKey($field)) ?? false;
    }

    public function isWritable(string $field): bool
    {
        return ! $this->isReadOnly($field) && ! $this->isDisabled($field);
    }

    /**
     * Required by the workflow — except, as in Redmine's
     * validate_required_fields, a field the tracker disables, a category
     * when the project has none and a version when none can be chosen.
     */
    public function isRequired(string $field): bool
    {
        if (($this->rules[self::ruleKey($field)] ?? null) !== 'required' || $this->isDisabled($field)) {
            return false;
        }

        return match ($field) {
            'category_id' => $this->project()?->issueCategories()->exists() ?? false,
            'fixed_version_id' => $this->hasAssignableVersions(),
            default => true,
        };
    }

    /**
     * Redmine's validate_required_fields on the issue about to be saved
     * ($issue, with the change applied) and its custom field values after the
     * change: every required field left blank is an error.
     *
     * @param  array<int|string, mixed>  $customFieldValues  custom_field_id => value after the change
     *
     * @throws ValidationException
     */
    public function assertRequiredFilled(Issue $issue, array $customFieldValues): void
    {
        $errors = [];

        // Redmine validates the subject's presence whatever the workflow says
        // (a read-only subject dropped from a new issue's input).
        if (! $this->isRequired('subject') && blank($issue->subject)) {
            $errors['subject'][] = __(':fieldを入力してください。', ['field' => self::label('subject')]);
        }

        foreach (array_keys($this->rules) as $field) {
            if (! $this->isRequired($field)) {
                continue;
            }

            if (str_starts_with($field, 'cf_')) {
                $customField = $this->customFields()->firstWhere('id', (int) substr($field, 3));

                if ($customField !== null && self::isBlank($customFieldValues[$customField->id] ?? null)) {
                    $errors["custom_fields.{$customField->id}"][] = __(':fieldを入力してください。', ['field' => $customField->name]);
                }

                continue;
            }

            $attribute = self::coreFieldKey($field);

            if ($attribute === 'assigned_to_id' && $issue->assigned_to_group_id !== null) {
                continue;
            }

            if (self::isBlank($issue->getAttribute($attribute))) {
                $errors[$attribute][] = __(':fieldを入力してください。', ['field' => self::label($attribute)]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The issue's custom fields $user sees (the ones a required rule can
     * name).
     *
     * @return Collection<int, CustomField>
     */
    public function customFields(): Collection
    {
        return once(fn () => $this->project() === null ? collect() : (clone $this->issue)->setRelation('project', $this->project())->relevantCustomFields($this->user));
    }

    private function hasAssignableVersions(): bool
    {
        $currentVersionId = $this->issue->exists ? $this->issue->getOriginal('fixed_version_id') : null;

        return $this->project()?->sharedVersions()
            ->contains(fn (Version $version) => $version->status === VersionStatus::Open || $version->id === $currentVersionId) ?? false;
    }

    private function tracker(): ?Tracker
    {
        return once(fn () => $this->issue->tracker_id === null ? null : Tracker::query()->find($this->issue->tracker_id));
    }

    private function project(): ?Project
    {
        return once(function () {
            if ($this->issue->project_id === null) {
                return null;
            }

            return $this->issue->relationLoaded('project') && $this->issue->project?->id === $this->issue->project_id
                ? $this->issue->project
                : Project::query()->find($this->issue->project_id);
        });
    }

    /**
     * The workflow names the parent Redmine's way (parent_issue_id); the
     * issue and the tracker call it parent_id.
     */
    private static function ruleKey(string $field): string
    {
        return $field === 'parent_id' ? 'parent_issue_id' : $field;
    }

    private static function coreFieldKey(string $field): string
    {
        return $field === 'parent_issue_id' ? 'parent_id' : $field;
    }

    private static function isBlank(mixed $value): bool
    {
        return is_array($value)
            ? collect($value)->every(fn (mixed $item): bool => blank($item))
            : blank($value);
    }

    private static function label(string $attribute): string
    {
        return [
            'tracker_id' => __('トラッカー'),
            'subject' => __('題名'),
            ...Tracker::disablableCoreFieldLabels(),
        ][$attribute] ?? $attribute;
    }
}
