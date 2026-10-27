<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\CustomizableType;
use App\Enums\EnumerationType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use App\Support\Format\Hours;
use App\Support\Issues\AssigneeChoice;
use App\Support\Locale\SupportedLocales;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Reads the Redmine-style keyword command lines in an incoming
 * mail's body ("Status: Closed", one per line) that override an
 * attribute on the created/replied-to issue, matching Redmine's
 * MailHandler#issue_attributes_from_keywords — narrowed to
 * status/priority/assigned_to/done_ratio/tracker/category/fixed_version/
 * start_date/due_date/estimated_hours/is_private/parent_issue. The label
 * itself is always accepted in fixed English (this app's own attribute
 * vocabulary — "parent issue" for example is chosen to match this app's
 * own attribute name rather than Redmine's English UI label "Parent
 * task", which none of this app's other keyword labels track literally
 * either, e.g. "done ratio" vs Redmine's "% Done") and, since A15-14,
 * additionally in Japanese (this app's own field labels, the same text
 * IssueColumns::native() uses) whenever the sender's own `language` or
 * the site's `default_language` is `ja` —
 * matching Redmine's `extract_keyword!`, which tries the English
 * `humanize`d name, the target user's own language, and
 * `Setting.default_language`, all at once. Custom-field keywords are
 * intentionally not translated — a custom field's name is free text set
 * by an administrator, not a translated label, so Redmine itself matches
 * it verbatim regardless of language.
 *
 * Used by IncomingMailService for new issues and replies alike.
 *
 * @phpstan-import-type HandlerOptions from \App\Services\IncomingMailService
 */
final class MailKeywordParser
{
    /**
     * Keyword label (lowercase, spaces) => the Issue attribute it sets.
     *
     * @var array<string, string>
     */
    private const array KEYWORD_ATTRIBUTES = [
        'status' => 'status_id',
        'priority' => 'priority_id',
        'assigned to' => 'assigned_to_id',
        'done ratio' => 'done_ratio',
        'tracker' => 'tracker_id',
        'category' => 'category_id',
        'fixed version' => 'fixed_version_id',
        'start date' => 'start_date',
        'due date' => 'due_date',
        'estimated hours' => 'estimated_hours',
        'private' => 'is_private',
        'parent issue' => 'parent_id',
    ];

    /**
     * KEYWORD_ATTRIBUTES's keyword => this app's own Japanese field label
     * for it (the same text IssueColumns::native() uses), accepted
     * alongside the English keyword when the sender's or site's language
     * is Japanese (A15-14).
     *
     * @var array<string, string>
     */
    private const array KEYWORD_LABELS_JA = [
        'status' => 'ステータス',
        'priority' => '優先度',
        'assigned to' => '担当者',
        'done ratio' => '進捗率',
        'tracker' => 'トラッカー',
        'category' => 'カテゴリ',
        'fixed version' => '対象バージョン',
        'start date' => '開始日',
        'due date' => '期日',
        'estimated hours' => '予定工数',
        'private' => '非公開',
        'parent issue' => '親課題',
    ];

    /**
     * Redmine's `POST /mail_handler` `issue[...]` param names (matching
     * MailHandlerController's permitted params, minus `project` — this
     * app resolves the target project from the subject/recipient before
     * any keyword handling runs, not through this same fallback
     * mechanism) => this service's own internal keyword string.
     *
     * @var array<string, string>
     */
    private const array ISSUE_PARAM_TO_KEYWORD = [
        'status' => 'status',
        'tracker' => 'tracker',
        'category' => 'category',
        'priority' => 'priority',
        'assigned_to' => 'assigned to',
        'fixed_version' => 'fixed version',
        'is_private' => 'private',
    ];

    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * Scans the body line by line for a recognized "Keyword: value" line
     * (see KEYWORD_ATTRIBUTES), resolving each value against the target
     * project and dropping the matched line from the returned body so it
     * isn't duplicated in the stored description/comment — matches
     * Redmine's destructive keyword-line removal in cleaned_up_text_body.
     * A keyword whose value can't be resolved (unknown status name, etc.)
     * is left as plain text in the body rather than silently vanishing,
     * so the sender can see what didn't take effect.
     *
     * @param  HandlerOptions  $options
     * @return array{attributes: array<string, int|string|float|bool>, body: string}
     */
    public function extractKeywordAttributes(string $body, Project $project, User $author, ?Issue $issue = null, array $options = []): array
    {
        $attributes = [];
        $kept = [];

        $allowed = $this->overridableKeywords($options);
        [$pattern, $labelToKeyword] = $this->keywordPattern($author);

        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^('.$pattern.')\s*:\s*(.+?)\s*$/iu', $line, $matches) === 1) {
                $keyword = $labelToKeyword[mb_strtolower($matches[1])] ?? null;

                if ($keyword !== null && $this->keywordAllowed($keyword, $allowed)
                    && $this->assignKeywordValue($attributes, $keyword, trim($matches[2]), $project, $author, $issue)) {
                    continue;
                }
            }

            $kept[] = $line;
        }

        // Redmine's get_keyword: a request-supplied issue[...] default fills
        // an attribute the body didn't (whether because no line matched, or
        // because that keyword isn't in allow_override) — applied
        // regardless of override permission, same as Redmine.
        foreach (self::ISSUE_PARAM_TO_KEYWORD as $param => $keyword) {
            $raw = $options['issue'][$param] ?? null;

            if (! is_string($raw) || $raw === '' || array_key_exists(self::KEYWORD_ATTRIBUTES[$keyword], $attributes)) {
                continue;
            }

            if ($keyword === 'assigned to' && array_key_exists('assigned_to_id', $attributes)) {
                continue;
            }

            $this->assignKeywordValue($attributes, $keyword, $raw, $project, $author, $issue);
        }

        return ['attributes' => $attributes, 'body' => trim(implode("\n", $kept))];
    }

    /**
     * Resolves one keyword's raw text and, if it resolves to something,
     * merges it into $attributes (an assignee resolves to one or more
     * Issue attributes via AssigneeChoice::decode(), everything else to a
     * single one via KEYWORD_ATTRIBUTES). Returns whether a value was set.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assignKeywordValue(array &$attributes, string $keyword, string $rawValue, Project $project, User $author, ?Issue $issue): bool
    {
        $value = $this->resolveKeywordValue($keyword, $rawValue, $project, $author, $issue);

        if ($value === null) {
            return false;
        }

        if ($keyword === 'assigned to') {
            // A user id, or `group:<id>` for a group assignee.
            $attributes = [...$attributes, ...AssigneeChoice::decode((string) $value)];

            return true;
        }

        $attributes[self::KEYWORD_ATTRIBUTES[$keyword]] = $value;

        return true;
    }

    /**
     * The keyword-line regex alternation and its label => keyword lookup
     * (lowercased, so a plain array lookup on mb_strtolower($match) works
     * uniformly for the case-insensitive English labels and the
     * case-less Japanese ones): the English label always, plus the
     * Japanese one for every keyword whose sender/site language is
     * Japanese (A15-14, matching Redmine's extract_keyword!).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function keywordPattern(User $author): array
    {
        $labelToKeyword = [];

        foreach (array_keys(self::KEYWORD_ATTRIBUTES) as $keyword) {
            $labelToKeyword[$keyword] = $keyword;
        }

        if ($this->acceptsJapaneseKeywords($author)) {
            foreach (self::KEYWORD_LABELS_JA as $keyword => $label) {
                $labelToKeyword[$label] = $keyword;
            }
        }

        $pattern = implode('|', array_map(fn (string $label) => preg_quote($label, '/'), array_keys($labelToKeyword)));

        return [$pattern, $labelToKeyword];
    }

    /**
     * Whether Japanese keyword labels should also be accepted: the
     * sender's own language is Japanese, or (Redmine always also tries
     * Setting.default_language, regardless of the sender's own) the
     * site's default language is.
     */
    private function acceptsJapaneseKeywords(User $author): bool
    {
        return $author->language === 'ja' || SupportedLocales::default() === 'ja';
    }

    /**
     * Scans the body for "Custom field name: value" lines (Redmine's
     * custom_field_values_from_keywords). Like the built-in keywords a line
     * counts only when mail_handler_allow_override names the field (its name
     * lowercased, spaces as underscores) or is `all` — Redmine's get_keyword
     * — and only for a field the sender could fill in on the form: one of the issue's tracker and project, visible to the sender's
     * roles, and editable. A value the field would not accept (not one of a
     * list's options, not a number, ...) leaves its line in the body.
     *
     * @param  HandlerOptions  $options
     * @return array{values: array<int, mixed>, body: string}
     */
    public function extractCustomFieldKeywords(string $body, Project $project, int $trackerId, User $author, array $options = []): array
    {
        $allowed = $this->overridableKeywords($options);
        $fields = $this->keywordCustomFields($project, $trackerId, $author)
            ->filter(fn (CustomField $field) => in_array('all', $allowed, true)
                || in_array(preg_replace('/\s+/', '_', mb_strtolower(trim($field->name))), $allowed, true))
            ->values();

        if ($fields->isEmpty()) {
            return ['values' => [], 'body' => $body];
        }

        $values = [];
        $kept = [];

        foreach (explode("\n", $body) as $line) {
            $matched = false;

            foreach ($fields as $field) {
                if (! array_key_exists($field->id, $values)
                    && preg_match('/^'.preg_quote($field->name, '/').'[ \t]*:[ \t]*(.+?)\s*$/iu', $line, $matches) === 1) {
                    $value = $this->customFieldValueFromKeyword($field, trim($matches[1]), $project);

                    if ($value !== null) {
                        $values[$field->id] = $value;
                        $matched = true;

                        break;
                    }
                }
            }

            if (! $matched) {
                $kept[] = $line;
            }
        }

        return ['values' => $values, 'body' => trim(implode("\n", $kept))];
    }

    /**
     * @return Collection<int, CustomField>
     */
    private function keywordCustomFields(Project $project, int $trackerId, User $author): Collection
    {
        $roles = $author->is_admin ? collect() : $this->authorization->rolesFor($author, $project);

        return CustomField::query()
            ->where('customized_type', CustomizableType::Issue)
            ->whereHas('trackers', fn ($query) => $query->where('trackers.id', $trackerId))
            ->with(['projects', 'roles'])
            ->orderBy('position')
            ->get()
            ->filter(fn (CustomField $field) => $field->appliesToProject($project)
                && ($author->is_admin || $field->visibleToRoles($roles))
                && $field->editableBy($author))
            ->values();
    }

    /**
     * The stored value for what a "Field: value" line says, or null when the
     * field would not take it. A field with fixed options is matched by its
     * label (a multiple one takes a comma-separated list); a yes/no field
     * takes yes/no/1/0/true/false.
     *
     * @return string|array<int, string>|null
     */
    private function customFieldValueFromKeyword(CustomField $field, string $text, Project $project): string|array|null
    {
        $parts = $field->multiple ? array_values(array_filter(array_map('trim', explode(',', $text)), fn (string $part) => $part !== '')) : [$text];
        $resolved = [];

        foreach ($parts as $part) {
            $value = $field->valueFromKeyword($part, $project) ?? '';

            $valid = $value !== '' && Validator::make(['value' => $value], ['value' => $field->validationRulesFor($project)])->passes();

            if (! $valid) {
                return null;
            }

            $resolved[] = $value;
        }

        if ($resolved === []) {
            return null;
        }

        return $field->multiple ? $resolved : $resolved[0];
    }

    /**
     * Redmine's allow_override: the attributes a sender may set with a
     * "Keyword: value" line — none by default (Redmine's empty option), `all`,
     * or a comma-separated list such as "status, priority, assigned_to";
     * matching ignores case and treats spaces as underscores.
     *
     * In real Redmine this is never a persisted site setting — it's a
     * per-invocation parameter only (the fetchmail rake task's own
     * `allow_override=` argument, or `POST /mail_handler`'s own
     * `allow_override` param; MailHandler#get_keyword reads only
     * handler_options[:allow_override], never Setting). This app instead
     * persists a site-wide `mail_handler_allow_override` setting, since
     * the scheduled mailbox fetch (fetchAndProcess()) has no per-request
     * value to draw one from. `POST /mail_handler`'s own
     * `allow_override` param, when given, is used for that request
     * instead of the site setting (A15-14) — matching Redmine for the one
     * path that actually has a per-request value to offer.
     *
     * @param  HandlerOptions  $options
     * @return array<int, string>
     */
    private function overridableKeywords(array $options = []): array
    {
        $raw = array_key_exists('allow_override', $options) ? (string) $options['allow_override'] : (string) Setting::get('mail_handler_allow_override', '');

        return collect(explode(',', $raw))
            ->map(fn (string $name) => preg_replace('/\s+/', '_', mb_strtolower(trim($name))))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function keywordAllowed(string $keyword, array $allowed): bool
    {
        $name = str_replace(' ', '_', $keyword);

        return in_array('all', $allowed, true)
            || in_array($name, $allowed, true)
            || ($keyword === 'private' && in_array('is_private', $allowed, true));
    }

    private function resolveKeywordValue(string $keyword, string $value, Project $project, User $author, ?Issue $issue = null): int|string|float|bool|null
    {
        if ($value === '') {
            return null;
        }

        if ($keyword === 'parent issue') {
            return $this->resolveParentIssueKeyword($value, $project, $author, $issue);
        }

        if ($keyword === 'done ratio') {
            return is_numeric($value) && (int) $value >= 0 && (int) $value <= 100 ? (int) $value : null;
        }

        if ($keyword === 'estimated hours') {
            $hours = Hours::parse($value);

            return $hours !== null && $hours >= 0 && $hours <= 9999.99 ? round($hours, 2) : null;
        }

        if ($keyword === 'start date' || $keyword === 'due date') {
            // A strict round-trip through DateTime rather than Laravel's
            // 'date' validation rule, since there's no Validator instance
            // in play here — rejects both malformed strings and anything
            // DateTime would otherwise silently roll over (e.g. Feb 30).
            $date = \DateTime::createFromFormat('!Y-m-d', $value);

            return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
        }

        if ($keyword === 'private') {
            // Only honored when the sender actually holds
            // set_issues_private on the target project — same gate the
            // manual issue form applies before ever including is_private
            // in its own submitted data, and the same decision the CSV
            // importer already made for its own is_private column.
            if (! $this->authorization->can($author, 'set_issues_private', $project)) {
                return null;
            }

            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        // Redmine's find_assignee_from_keyword: an assignable user by mail or
        // name, else (issue_group_assignment on) an assignable group by name.
        if ($keyword === 'assigned to') {
            $userId = $project->assignableUsers()
                ->first(fn (User $user) => strcasecmp($user->email, $value) === 0 || strcasecmp($user->name, $value) === 0)?->id;

            if ($userId !== null) {
                return (int) $userId;
            }

            $group = Issue::groupAssignmentEnabled()
                ? $project->assignableGroups()->first(fn (Group $group) => strcasecmp($group->name, $value) === 0)
                : null;

            return $group !== null ? AssigneeChoice::forGroup($group) : null;
        }

        $id = match ($keyword) {
            'status' => IssueStatus::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->value('id'),
            'priority' => Enumeration::query()->ofType(EnumerationType::IssuePriority)->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->value('id'),
            // Scoped to the project's own trackers/categories/versions —
            // the same boundary the manual issue form enforces, so a
            // keyword line can't assign a tracker/category/version that
            // doesn't actually belong to this project.
            // Only a tracker the sender may use (Redmine's
            // allowed_target_trackers, plus the replied issue's own).
            'tracker' => Issue::allowedTargetTrackers($project, $author, $issue?->tracker_id)->first(fn (Tracker $tracker) => strcasecmp($tracker->name, $value) === 0)?->id,
            'category' => $project->issueCategories()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->value('id'),
            'fixed version' => $project->versions()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->value('id'),
            default => null,
        };

        return $id !== null ? (int) $id : null;
    }

    /**
     * Accepts a bare issue number or a "#123"-style reference, matching
     * how every other issue-id reference in this app's mail/commit
     * parsing is written. Scoped to the same project as the manual issue
     * form's own parent_id rule, and rejects self/descendant cycles via
     * Issue::descendantIds() the same way the form does (its own inline
     * ancestor-walk closure isn't reusable from a service class, so this
     * uses the already-shared descendantIds() the issue-relation
     * cycle/ancestor checks also rely on, rather than duplicating that
     * walk a third time). $issue is null when creating a brand new
     * issue, which can never already have descendants, so no cycle check
     * is needed there.
     */
    private function resolveParentIssueKeyword(string $value, Project $project, User $author, ?Issue $issue): ?int
    {
        $parentId = (int) preg_replace('/\D/', '', $value);

        if ($parentId === 0 || ($issue !== null && $parentId === $issue->id)) {
            return null;
        }

        $parent = Issue::query()->where('id', $parentId)->where('project_id', $project->id)->visibleTo($author, $project)->first();

        if ($parent === null) {
            return null;
        }

        if ($issue !== null && $issue->descendantIds()->contains($parent->id)) {
            return null;
        }

        return $parent->id;
    }
}
