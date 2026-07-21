<?php

use App\Enums\CustomFieldFormat;
use App\Enums\EnumerationType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Services\IssueService;
use App\Services\WorkflowService;
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

    public string $comment = '';

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
            $this->status_id = IssueStatus::query()->orderBy('position')->first()?->id;
            $this->priority_id = Enumeration::query()
                ->ofType(EnumerationType::IssuePriority)
                ->where('is_default', true)
                ->first()?->id;
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
        return $this->project->users;
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

    #[Computed]
    public function projectVersions(): Collection
    {
        return $this->project->versions;
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
            'fixed_version_id' => ['nullable', Rule::exists('versions', 'id')->where('project_id', $this->project->id)],
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
            // Kilobytes, derived from media-library's own byte-based cap so
            // the two limits can't silently drift apart.
            'newAttachments.*' => ['file', 'max:'.intdiv(config('media-library.max_file_size'), 1024)],
        ];

        foreach ($this->fieldRules as $field => $rule) {
            if ($rule === 'required' && isset($rules[$field])) {
                $rules[$field] = [...array_diff($rules[$field], ['nullable']), 'required'];
            }
        }

        if ($this->issue) {
            $rules['status_id'] = ['required', 'exists:issue_statuses,id'];
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
        unset($data['customFieldValues'], $data['newAttachments']);

        if ($this->issue) {
            if ($data['status_id'] !== $this->issue->status_id) {
                $this->authorize('transitionTo', [$this->issue, IssueStatus::findOrFail($data['status_id'])]);
            }

            $issue = app(IssueService::class)->update($this->issue, $data, auth()->user(), $this->comment ?: null);
        } else {
            $data['project_id'] = $this->project->id;
            $data['status_id'] = $this->status_id;
            $issue = app(IssueService::class)->create($data, auth()->user());
        }

        $issue->setCustomFieldValues($customFieldData);

        foreach ($this->newAttachments as $file) {
            $issue->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('attachments');
        }

        $this->redirect(route('issues.show', [$this->project, $issue]), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $issue ? "#{$issue->id} を編集" : '新規課題' }}
    </h1>

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

        <div>
            <label class="block text-sm font-medium text-gray-700">説明</label>
            <textarea wire:model="description" rows="4" @disabled($this->isReadOnly('description'))
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">優先度</label>
                <select wire:model="priority_id" @disabled($this->isReadOnly('priority_id'))
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @foreach ($this->priorities as $priority)
                        <option value="{{ $priority->id }}">{{ $priority->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">担当者</label>
                <select wire:model="assigned_to_id" @disabled($this->isReadOnly('assigned_to_id'))
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">未割当</option>
                    @foreach ($this->projectMembers as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">開始日</label>
                <input type="date" wire:model="start_date" @disabled($this->isReadOnly('start_date'))
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">期日</label>
                <input type="date" wire:model="due_date" @disabled($this->isReadOnly('due_date'))
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
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
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">親課題ID</label>
            <input type="number" wire:model="parent_id" placeholder="例: 123"
                class="mt-1 block w-32 rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('parent_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

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
            <div>
                <label class="block text-sm font-medium text-gray-700">進捗率 ({{ $done_ratio }}%)</label>
                <input type="range" wire:model="done_ratio" min="0" max="100" step="10" class="mt-1 block w-full">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">コメント</label>
                <textarea wire:model="comment" rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"
                    placeholder="変更内容についてのコメント(任意)"></textarea>
            </div>
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
