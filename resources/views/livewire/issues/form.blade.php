<?php

use App\Enums\CustomFieldFormat;
use App\Enums\EnumerationType;
use App\Enums\VersionStatus;
use App\Exceptions\StaleIssueUpdateException;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\Version;
use App\Services\IssueService;
use App\Services\WorkflowService;
use App\Support\Attachments\AttachmentValidationRules;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    public Project $project;

    public ?Issue $issue = null;

    public ?int $tracker_id = null;

    public ?int $status_id = null;

    public ?int $priority_id = null;

    public ?int $category_id = null;

    public ?int $assigned_to_id = null;

    public ?int $fixed_version_id = null;

    public ?int $parent_id = null;

    public string $subject = '';

    public string $description = '';

    public ?string $start_date = null;

    public ?string $due_date = null;

    public int $done_ratio = 0;

    public string $estimated_hours = '';

    public bool $is_private = false;

    public int $lockVersion = 0;

    public string $comment = '';

    public ?int $logTimeActivityId = null;

    public string $logTimeHours = '';

    public string $logTimeComments = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newAttachments = [];

    /** @var array<int|string, mixed> custom_field_id => raw input (or array for multi-value) */
    public array $customFieldValues = [];

    /** @var array<string, string> */
    public array $fieldRules = [];

    /** @var array<int, IssueStatus> */
    public Collection $allowedStatuses;

    public function mount(Project $project, ?Issue $issue = null): void
    {
        $this->project = $project;
        $this->allowedStatuses = collect();

        if ($issue?->exists) {
            $this->authorize('update', $issue);

            $this->issue = $issue;
            $this->tracker_id = $issue->tracker_id;
            $this->status_id = $issue->status_id;
            $this->priority_id = $issue->priority_id;
            $this->category_id = $issue->category_id;
            $this->assigned_to_id = $issue->assigned_to_id;
            $this->fixed_version_id = $issue->fixed_version_id;
            $this->parent_id = $issue->parent_id;
            $this->subject = $issue->subject;
            $this->description = (string) $issue->description;
            $this->start_date = $issue->start_date?->toDateString();
            $this->due_date = $issue->due_date?->toDateString();
            $this->done_ratio = $issue->done_ratio;
            $this->estimated_hours = $issue->estimated_hours !== null ? (string) $issue->estimated_hours : '';
            $this->is_private = $issue->is_private;
            $this->lockVersion = $issue->lock_version;

            if ($this->canLogTime) {
                $this->logTimeActivityId = Enumeration::query()
                    ->ofType(EnumerationType::TimeEntryActivity)
                    ->where('is_default', true)
                    ->first()?->id;
            }

            $this->fieldRules = app(WorkflowService::class)->fieldRules($issue, auth()->user());
            $this->allowedStatuses = app(WorkflowService::class)->allowedTransitions($issue, auth()->user())
                ->push($issue->status)
                ->unique('id');

            foreach ($issue->relevantCustomFields() as $field) {
                $this->customFieldValues[$field->id] = $field->multiple
                    ? $issue->customFieldValues->where('custom_field_id', $field->id)->map(fn ($v) => $v->value())->all()
                    : $issue->customValue($field);
            }
        } else {
            $this->authorize('create', [Issue::class, $project]);

            $this->tracker_id = $project->trackers->first()?->id;
            $this->priority_id = Enumeration::query()
                ->ofType(EnumerationType::IssuePriority)
                ->where('is_default', true)
                ->first()?->id;
            $this->start_date = now()->toDateString();

            $this->prefillFromCopySource($project);

            // Resolved after the copy-from prefill (which may itself change
            // tracker_id) so the default status matches whichever tracker
            // actually ends up selected, not the project's first one.
            $this->status_id = $this->defaultStatusIdForTracker($this->tracker_id);
        }
    }

    /**
     * Falls back to the global first status when the tracker has no
     * default_status_id of its own set, or no tracker is selected yet.
     */
    private function defaultStatusIdForTracker(?int $trackerId): ?int
    {
        $trackerDefault = $trackerId !== null
            ? Tracker::query()->whereKey($trackerId)->value('default_status_id')
            : null;

        return $trackerDefault ?? IssueStatus::query()->orderBy('position')->first()?->id;
    }

    /**
     * Only re-derives the default status while creating a new issue —
     * changing the tracker mid-edit shouldn't silently change an
     * already-set status out from under the user.
     */
    public function updatedTrackerId(): void
    {
        if ($this->issue === null) {
            $this->status_id = $this->defaultStatusIdForTracker($this->tracker_id);
        }
    }

    /**
     * Prefills a new issue's fields from ?copy_from=<id> — the source
     * issue's own tracker/status/journals/attachments/relations are
     * deliberately not carried over: status resets to the normal new-
     * issue default above, and the rest are considered out of scope for
     * a lightweight "start from a similar issue" copy.
     */
    private function prefillFromCopySource(Project $project): void
    {
        $sourceId = request()->integer('copy_from');

        if ($sourceId === 0) {
            return;
        }

        $source = Issue::query()->where('project_id', $project->id)->find($sourceId);

        if ($source === null || auth()->user()?->cannot('view', $source)) {
            return;
        }

        $this->tracker_id = $source->tracker_id;
        $this->priority_id = $source->priority_id;
        $this->category_id = $source->category_id;
        $this->assigned_to_id = $source->assigned_to_id;
        $this->fixed_version_id = $source->fixed_version_id;
        $this->subject = $source->subject;
        $this->description = (string) $source->description;
        $this->start_date = $source->start_date?->toDateString();
        $this->due_date = $source->due_date?->toDateString();

        foreach ($source->relevantCustomFields() as $field) {
            $this->customFieldValues[$field->id] = $field->multiple
                ? $source->customFieldValues->where('custom_field_id', $field->id)->map(fn ($v) => $v->value())->all()
                : $source->customValue($field);
        }
    }

    #[Computed]
    public function projectTrackers(): Collection
    {
        return $this->project->trackers;
    }

    #[Computed]
    public function priorities(): Collection
    {
        return Enumeration::query()->ofType(EnumerationType::IssuePriority)->orderBy('position')->get();
    }

    #[Computed]
    public function projectMembers(): Collection
    {
        return $this->project->assignableUsers();
    }

    #[Computed]
    public function projectCategories(): Collection
    {
        return $this->project->issueCategories;
    }

    /**
     * Prefills the assignee from the category's default, matching
     * Redmine's own behaviour — a UI convenience only, so it deliberately
     * doesn't override an assignee the user already picked.
     */
    public function updatedCategoryId(): void
    {
        if ($this->assigned_to_id !== null || $this->category_id === null) {
            return;
        }

        $this->assigned_to_id = IssueCategory::query()->whereKey($this->category_id)->value('assigned_to_id');
    }

    /**
     * Matches Redmine's Issue#assignable_versions: only open versions are
     * offered for new assignment, but an already-assigned version stays
     * selectable even once locked or closed, so editing an issue doesn't
     * silently drop it from the dropdown (and thus from the issue) just
     * because someone locked the version in the meantime. Compared against
     * the issue's persisted fixed_version_id (not the live property) —
     * otherwise this would trivially allow any submitted value, since the
     * live property always equals whatever was just set.
     *
     * @return Collection<int, Version>
     */
    #[Computed]
    public function projectVersions(): Collection
    {
        $currentVersionId = $this->issue?->fixed_version_id;

        return $this->project->versions
            ->filter(fn (Version $version) => $version->status === VersionStatus::Open || $version->id === $currentVersionId)
            ->values();
    }

    /**
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function customFields(): Collection
    {
        if ($this->tracker_id === null) {
            return collect();
        }

        return (new Issue(['tracker_id' => $this->tracker_id]))
            ->setRelation('project', $this->project)
            ->relevantCustomFields();
    }

    public function isRequired(string $field): bool
    {
        return ($this->fieldRules[$field] ?? null) === 'required';
    }

    public function isReadOnly(string $field): bool
    {
        return ($this->fieldRules[$field] ?? null) === 'read_only';
    }

    #[Computed]
    public function currentTracker(): ?Tracker
    {
        return Tracker::query()->find($this->tracker_id);
    }

    /**
     * Matches Redmine's Tracker#disabled_core_fields: a tracker can hide
     * a core field from the issue form entirely, distinct from the
     * per-workflow read_only/required rules above.
     */
    public function isCoreFieldDisabled(string $field): bool
    {
        return $this->currentTracker?->isCoreFieldDisabled($field) ?? false;
    }

    #[Computed]
    public function doneRatioIsStatusDerived(): bool
    {
        return Setting::get('issue_done_ratio', 'issue_field') === 'issue_status';
    }

    /**
     * Whether this (existing) issue has children whose own values roll up
     * into these fields — matches Redmine's parent_issue_priority/_dates/
     * _done_ratio settings, which make the parent's own field
     * non-editable once it has children, since the next child save would
     * just overwrite whatever was manually entered.
     */
    #[Computed]
    public function priorityIsDerived(): bool
    {
        return $this->issue !== null && ! $this->issue->isLeaf() && Setting::get('parent_issue_priority', true);
    }

    #[Computed]
    public function datesAreDerived(): bool
    {
        return $this->issue !== null && ! $this->issue->isLeaf() && Setting::get('parent_issue_dates', true);
    }

    #[Computed]
    public function doneRatioIsParentDerived(): bool
    {
        return $this->issue !== null && ! $this->issue->isLeaf() && Setting::get('parent_issue_done_ratio', true);
    }

    #[Computed]
    public function canLogTime(): bool
    {
        return app(AuthorizationService::class)->can(auth()->user(), 'log_time', $this->project);
    }

    #[Computed]
    public function timeEntryActivities(): Collection
    {
        return Enumeration::query()->ofType(EnumerationType::TimeEntryActivity)->orderBy('position')->get();
    }

    public function save(): void
    {
        $rules = [
            // Scoped to this project (rather than a bare exists:table,id) so a
            // crafted request can't attach an issue to another project's
            // tracker/version, assign it to a non-member, or set a priority_id
            // that's actually a different enumeration type's row.
            'tracker_id' => ['required', Rule::exists('project_tracker', 'tracker_id')->where('project_id', $this->project->id)],
            'priority_id' => ['required', Rule::exists('enumerations', 'id')->where('type', EnumerationType::IssuePriority->value)],
            'category_id' => ['nullable', Rule::exists('issue_categories', 'id')->where('project_id', $this->project->id)],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_to_id' => ['nullable', Rule::exists('members', 'user_id')->where('project_id', $this->project->id)],
            'fixed_version_id' => ['nullable', Rule::in($this->projectVersions->pluck('id')->all())],
            'parent_id' => [
                'nullable',
                Rule::exists('issues', 'id')->where('project_id', $this->project->id),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $this->issue === null) {
                        return;
                    }

                    $ancestorId = (int) $value;

                    while ($ancestorId !== null) {
                        if ($ancestorId === $this->issue->id) {
                            $fail('選択した課題はこの課題自身またはその子孫であるため、親に設定できません。');

                            return;
                        }

                        $ancestorId = Issue::query()->whereKey($ancestorId)->value('parent_id');
                    }
                },
            ],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'done_ratio' => ['integer', 'min:0', 'max:100'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            // Kilobytes, derived from media-library's own byte-based cap so
            // the two limits can't silently drift apart.
            'newAttachments.*' => AttachmentValidationRules::rules(),
        ];

        foreach ($this->fieldRules as $field => $rule) {
            if ($rule === 'required' && isset($rules[$field])) {
                $rules[$field] = [...array_diff($rules[$field], ['nullable']), 'required'];
            }
        }

        if ($this->issue) {
            $rules['status_id'] = ['required', 'exists:issue_statuses,id'];
        }

        if ($this->issue && $this->canLogTime) {
            $rules['logTimeHours'] = ['nullable', 'numeric', 'min:0.01', 'max:1000'];
            $rules['logTimeActivityId'] = [
                'required_with:logTimeHours',
                Rule::exists('enumerations', 'id')->where('type', EnumerationType::TimeEntryActivity->value),
            ];
            $rules['logTimeComments'] = ['nullable', 'string'];
        }

        foreach ($this->customFields as $field) {
            $key = "customFieldValues.{$field->id}";
            $presence = ($field->is_required || $this->isRequired("cf_{$field->id}")) ? 'required' : 'nullable';

            if ($field->multiple) {
                $rules[$key] = [$presence, 'array'];
                $rules["{$key}.*"] = $field->format()->validationRules($field);
            } else {
                $rules[$key] = [$presence, ...$field->format()->validationRules($field)];
            }
        }

        $data = $this->validate($rules);
        $customFieldData = $data['customFieldValues'] ?? [];
        $logTimeHours = $data['logTimeHours'] ?? null;
        $logTimeActivityId = $data['logTimeActivityId'] ?? null;
        $logTimeComments = $data['logTimeComments'] ?? '';
        unset($data['customFieldValues'], $data['newAttachments'], $data['logTimeHours'], $data['logTimeActivityId'], $data['logTimeComments']);

        // An empty text input means "no estimate", not zero — store null
        // rather than letting the decimal cast coerce '' to 0.00.
        $data['estimated_hours'] = $data['estimated_hours'] !== '' ? $data['estimated_hours'] : null;

        // Only ever set is_private when the user actually holds
        // set_issues_private — otherwise leave the key out entirely so an
        // unrelated save by a lower-permission editor can't silently flip
        // an already-private issue back to public.
        if (auth()->user()->can('setPrivate', [Issue::class, $this->project])) {
            $data['is_private'] = $this->is_private;
        }

        if ($this->issue) {
            if ($data['status_id'] !== $this->issue->status_id) {
                $this->authorize('transitionTo', [$this->issue, IssueStatus::findOrFail($data['status_id'])]);
            }

            try {
                $issue = app(IssueService::class)->update($this->issue, $data, auth()->user(), $this->comment ?: null, $customFieldData, $this->lockVersion);
            } catch (StaleIssueUpdateException) {
                $this->addError('lockVersion', 'この課題は他のユーザーによって更新されています。ページを再読み込みして最新の内容を確認してから、再度保存してください。');

                return;
            }
        } else {
            $data['project_id'] = $this->project->id;
            $data['status_id'] = $this->status_id;
            $issue = app(IssueService::class)->create($data, auth()->user(), $customFieldData);
        }

        foreach ($this->newAttachments as $file) {
            $issue->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('attachments');
        }

        if (filled($logTimeHours)) {
            TimeEntry::create([
                'project_id' => $this->project->id,
                'issue_id' => $issue->id,
                'user_id' => auth()->id(),
                'activity_id' => $logTimeActivityId,
                'hours' => $logTimeHours,
                'spent_on' => now()->toDateString(),
                'comments' => $logTimeComments,
            ]);
        }

        $this->redirect(route('issues.show', [$this->project, $issue]), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $issue ? "#{$issue->id} を編集" : '新規課題' }}
    </h1>

    @error('lockVersion')
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>
    @enderror

    <form wire:submit="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">トラッカー</label>
                <select wire:model.live="tracker_id" @disabled($this->isReadOnly('tracker_id'))
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @foreach ($this->projectTrackers as $tracker)
                        <option value="{{ $tracker->id }}">{{ $tracker->name }}</option>
                    @endforeach
                </select>
                @error('tracker_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            @if ($issue)
                <div>
                    <label class="block text-sm font-medium text-gray-700">ステータス</label>
                    <select wire:model="status_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                        @foreach ($allowedStatuses as $status)
                            <option value="{{ $status->id }}">{{ $status->name }}</option>
                        @endforeach
                    </select>
                    @error('status_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">
                題名 @if ($this->isRequired('subject'))<span class="text-red-500">*</span>@endif
            </label>
            <input type="text" wire:model="subject" @disabled($this->isReadOnly('subject'))
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('subject') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        @unless ($this->isCoreFieldDisabled('description'))
            <div>
                <label class="block text-sm font-medium text-gray-700">説明</label>
                <textarea wire:model="description" rows="4" @disabled($this->isReadOnly('description'))
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            </div>
        @endunless

        <div class="grid grid-cols-2 gap-4">
            @unless ($this->isCoreFieldDisabled('priority_id'))
                <div>
                    <label class="block text-sm font-medium text-gray-700">優先度</label>
                    <select wire:model="priority_id" @disabled($this->isReadOnly('priority_id') || $this->priorityIsDerived)
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                        @foreach ($this->priorities as $priority)
                            <option value="{{ $priority->id }}">{{ $priority->name }}</option>
                        @endforeach
                    </select>
                    @if ($this->priorityIsDerived)
                        <p class="mt-1 text-xs text-gray-500">未クローズの子課題のうち最高優先度から自動算出されます。</p>
                    @endif
                </div>
            @endunless

            @unless ($this->isCoreFieldDisabled('assigned_to_id'))
                <div>
                    <label class="block text-sm font-medium text-gray-700">
                        担当者
                        @if (! $this->isReadOnly('assigned_to_id') && $assigned_to_id !== auth()->id() && $this->projectMembers->contains('id', auth()->id()))
                            <button type="button" wire:click="$set('assigned_to_id', {{ auth()->id() }})"
                                class="ml-1 text-xs font-normal text-indigo-600 hover:underline">
                                自分に割り当てる
                            </button>
                        @endif
                    </label>
                    <select wire:model="assigned_to_id" @disabled($this->isReadOnly('assigned_to_id'))
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                        <option value="">未割当</option>
                        @foreach ($this->projectMembers as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endunless
        </div>

        @if (! $this->isCoreFieldDisabled('start_date') || ! $this->isCoreFieldDisabled('due_date'))
            <div class="grid grid-cols-2 gap-4">
                @unless ($this->isCoreFieldDisabled('start_date'))
                    <div>
                        <label class="block text-sm font-medium text-gray-700">開始日</label>
                        <input type="date" wire:model="start_date" @disabled($this->isReadOnly('start_date') || $this->datesAreDerived)
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    </div>
                @endunless
                @unless ($this->isCoreFieldDisabled('due_date'))
                    <div>
                        <label class="block text-sm font-medium text-gray-700">期日</label>
                        <input type="date" wire:model="due_date" @disabled($this->isReadOnly('due_date') || $this->datesAreDerived)
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    </div>
                @endunless
            </div>
            @if ($this->datesAreDerived)
                <p class="-mt-3 text-xs text-gray-500">子課題の開始日〜期日の範囲から自動算出されます。</p>
            @endif
        @endif

        @unless ($this->isCoreFieldDisabled('estimated_hours'))
            <div>
                <label class="block text-sm font-medium text-gray-700">予定工数(時間)</label>
                <input type="number" step="0.01" min="0" wire:model="estimated_hours"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('estimated_hours') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endunless

        @if (! $this->isCoreFieldDisabled('category_id') || ! $this->isCoreFieldDisabled('fixed_version_id'))
            <div class="grid grid-cols-2 gap-4">
                @unless ($this->isCoreFieldDisabled('category_id'))
                    <div>
                        <label class="block text-sm font-medium text-gray-700">カテゴリ</label>
                        <select wire:model.live="category_id" @disabled($this->isReadOnly('category_id'))
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                            <option value="">なし</option>
                            @foreach ($this->projectCategories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('category_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endunless

                @unless ($this->isCoreFieldDisabled('fixed_version_id'))
                    <div>
                        <label class="block text-sm font-medium text-gray-700">対象バージョン</label>
                        <select wire:model="fixed_version_id" @disabled($this->isReadOnly('fixed_version_id'))
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                            <option value="">なし</option>
                            @foreach ($this->projectVersions as $version)
                                <option value="{{ $version->id }}">{{ $version->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endunless
            </div>
        @endif

        @unless ($this->isCoreFieldDisabled('parent_id'))
            <div>
                <label class="block text-sm font-medium text-gray-700">親課題ID</label>
                <input type="number" wire:model="parent_id" placeholder="例: 123"
                    class="mt-1 block w-32 rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('parent_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endunless

        @can('setPrivate', [\App\Models\Issue::class, $project])
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="is_private" class="rounded border-gray-300">
                非公開課題にする(作成者・担当者と、閲覧範囲が「すべて」のロールのみ閲覧可能)
            </label>
        @endcan

        @if ($this->customFields->isNotEmpty())
            <div class="space-y-4 border-t border-gray-200 pt-4">
                @foreach ($this->customFields as $field)
                    <x-custom-field-input :field="$field" wire-model="customFieldValues"
                        :required="$field->is_required || $this->isRequired('cf_'.$field->id)"
                        :disabled="$this->isReadOnly('cf_'.$field->id)" />
                @endforeach
            </div>
        @endif

        <div>
            <label class="block text-sm font-medium text-gray-700">添付ファイル</label>
            <input type="file" wire:model="newAttachments" multiple
                class="mt-1 block w-full text-sm text-gray-700">
            @error('newAttachments.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            @php $existingAttachments = $issue?->attachments(); @endphp
            @if ($existingAttachments?->isNotEmpty())
                <ul class="mt-2 space-y-1">
                    @foreach ($existingAttachments as $media)
                        <li class="text-sm text-gray-600">{{ $media->file_name }} ({{ $media->human_readable_size }})</li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($issue)
            @unless ($this->isCoreFieldDisabled('done_ratio'))
                <div>
                    <label class="block text-sm font-medium text-gray-700">進捗率 ({{ $done_ratio }}%)</label>
                    <input type="range" wire:model="done_ratio" min="0" max="100" step="10" class="mt-1 block w-full"
                        @disabled($this->doneRatioIsStatusDerived || $this->doneRatioIsParentDerived)>
                    @if ($this->doneRatioIsStatusDerived)
                        <p class="mt-1 text-xs text-gray-500">設定によりステータスから自動算出されます。</p>
                    @elseif ($this->doneRatioIsParentDerived)
                        <p class="mt-1 text-xs text-gray-500">子課題の進捗率(予定工数で重み付け)から自動算出されます。</p>
                    @endif
                </div>
            @endunless

            <div>
                <label class="block text-sm font-medium text-gray-700">コメント</label>
                <textarea wire:model="comment" rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"
                    placeholder="変更内容についてのコメント(任意)"></textarea>
            </div>

            @if ($this->canLogTime)
                <fieldset class="rounded-md border border-gray-200 p-4">
                    <legend class="px-1 text-sm font-medium text-gray-700">工数を記録</legend>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">時間</label>
                            <input type="number" step="0.01" wire:model="logTimeHours"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            @error('logTimeHours') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">作業分類</label>
                            <select wire:model="logTimeActivityId" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option value="">選択してください</option>
                                @foreach ($this->timeEntryActivities as $activity)
                                    <option value="{{ $activity->id }}">{{ $activity->name }}</option>
                                @endforeach
                            </select>
                            @error('logTimeActivityId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-xs font-medium text-gray-700">工数のコメント</label>
                        <input type="text" wire:model="logTimeComments"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        @error('logTimeComments') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </fieldset>
            @endif
        @endif

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ $issue ? route('issues.show', [$project, $issue]) : route('issues.index', $project) }}"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
