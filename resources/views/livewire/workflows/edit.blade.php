<?php

use App\Enums\CustomizableType;
use App\Enums\WorkflowFieldRuleType;
use App\Models\CustomField;
use App\Models\IssueStatus;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\WorkflowFieldRule;
use App\Models\WorkflowTransition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    /**
     * Core issue fields a workflow rule can require or lock — matches the
     * field keys issues/form.blade.php's isRequired()/isReadOnly() already
     * check by name. Custom fields (cf_<id>) are appended dynamically once
     * a tracker is selected, since which ones apply depends on it.
     *
     * @var array<string, string>
     */
    private const array CORE_FIELDS = [
        'tracker_id' => 'トラッカー',
        'subject' => '題名',
        'description' => '説明',
        'priority_id' => '優先度',
        'category_id' => 'カテゴリ',
        'assigned_to_id' => '担当者',
        'start_date' => '開始日',
        'due_date' => '期日',
        'fixed_version_id' => '対象バージョン',
    ];

    public ?int $tracker_id = null;

    public ?int $role_id = null;

    /**
     * Which of the workflow table's three contexts is being edited: the
     * general case, or the transitions/rules that apply *additionally*
     * when the acting user is specifically the issue's author or
     * assignee. Deliberately not editing new-issue (null old_status_id)
     * transitions here — nothing in this app currently consults them
     * (IssueService::create()'s status defaulting doesn't look at the
     * workflow table at all), so there's no observable behavior to wire a
     * grid to yet.
     *
     * @var 'general'|'author'|'assignee'
     */
    public string $context = 'general';

    /** @var array<string, bool> keyed "{old_status_id}-{new_status_id}" */
    public array $transitions = [];

    /** @var array<string, string> keyed "{field_name}-{status_id}" => ''|'required'|'read_only' */
    public array $fieldRules = [];

    public function mount(): void
    {
        $this->authorize('manage', WorkflowTransition::class);
    }

    #[Computed]
    public function trackers(): Collection
    {
        return Tracker::query()->orderBy('position')->get();
    }

    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->orderBy('position')->get();
    }

    #[Computed]
    public function statuses(): Collection
    {
        return IssueStatus::query()->orderBy('position')->get();
    }

    /**
     * @return Collection<string, string> field key => label
     */
    #[Computed]
    public function fields(): Collection
    {
        $fields = collect(self::CORE_FIELDS);

        if ($this->tracker_id === null) {
            return $fields;
        }

        $customFields = CustomField::query()
            ->where('customized_type', CustomizableType::Issue)
            ->whereHas('trackers', fn ($query) => $query->where('trackers.id', $this->tracker_id))
            ->orderBy('position')
            ->get()
            ->mapWithKeys(fn (CustomField $field) => ["cf_{$field->id}" => $field->name]);

        return $fields->merge($customFields);
    }

    public function updatedTrackerId(): void
    {
        $this->loadMatrix();
    }

    public function updatedRoleId(): void
    {
        $this->loadMatrix();
    }

    public function updatedContext(): void
    {
        $this->loadMatrix();
    }

    private function loadMatrix(): void
    {
        $this->transitions = [];
        $this->fieldRules = [];

        if ($this->tracker_id === null || $this->role_id === null) {
            return;
        }

        [$author, $assignee] = $this->contextFlags();

        $this->transitions = WorkflowTransition::query()
            ->where('tracker_id', $this->tracker_id)
            ->where('role_id', $this->role_id)
            ->where('author', $author)
            ->where('assignee', $assignee)
            ->whereNotNull('old_status_id')
            ->get()
            ->mapWithKeys(fn (WorkflowTransition $t) => ["{$t->old_status_id}-{$t->new_status_id}" => true])
            ->all();

        $this->fieldRules = WorkflowFieldRule::query()
            ->where('tracker_id', $this->tracker_id)
            ->where('role_id', $this->role_id)
            ->where('author', $author)
            ->where('assignee', $assignee)
            ->get()
            ->mapWithKeys(fn (WorkflowFieldRule $r) => ["{$r->field_name}-{$r->status_id}" => $r->rule->value])
            ->all();
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function contextFlags(): array
    {
        return match ($this->context) {
            'author' => [true, false],
            'assignee' => [false, true],
            default => [false, false],
        };
    }

    public function save(): void
    {
        abort_if($this->tracker_id === null || $this->role_id === null, 422);

        [$author, $assignee] = $this->contextFlags();

        DB::transaction(function () use ($author, $assignee): void {
            WorkflowTransition::query()
                ->where('tracker_id', $this->tracker_id)
                ->where('role_id', $this->role_id)
                ->where('author', $author)
                ->where('assignee', $assignee)
                ->whereNotNull('old_status_id')
                ->delete();

            foreach ($this->transitions as $key => $checked) {
                if (! $checked) {
                    continue;
                }

                [$oldStatusId, $newStatusId] = explode('-', $key);

                WorkflowTransition::create([
                    'tracker_id' => $this->tracker_id,
                    'role_id' => $this->role_id,
                    'old_status_id' => (int) $oldStatusId,
                    'new_status_id' => (int) $newStatusId,
                    'author' => $author,
                    'assignee' => $assignee,
                ]);
            }

            WorkflowFieldRule::query()
                ->where('tracker_id', $this->tracker_id)
                ->where('role_id', $this->role_id)
                ->where('author', $author)
                ->where('assignee', $assignee)
                ->delete();

            foreach ($this->fieldRules as $key => $rule) {
                $ruleType = WorkflowFieldRuleType::tryFrom($rule);

                if ($ruleType === null) {
                    continue;
                }

                [$fieldName, $statusId] = explode('-', $key, 2);

                WorkflowFieldRule::create([
                    'tracker_id' => $this->tracker_id,
                    'role_id' => $this->role_id,
                    'status_id' => (int) $statusId,
                    'field_name' => $fieldName,
                    'rule' => $ruleType,
                    'author' => $author,
                    'assignee' => $assignee,
                ]);
            }
        });

        session()->flash('status', 'ワークフローを保存しました。');
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold text-gray-900 mb-6">ワークフロー管理</h1>

    <div class="mb-6 grid grid-cols-3 gap-4 rounded-md border border-gray-200 bg-white p-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">トラッカー</label>
            <select wire:model.live="tracker_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="">選択してください</option>
                @foreach ($this->trackers as $tracker)
                    <option value="{{ $tracker->id }}">{{ $tracker->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">ロール</label>
            <select wire:model.live="role_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="">選択してください</option>
                @foreach ($this->roles as $role)
                    <option value="{{ $role->id }}">{{ $role->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">適用対象</label>
            <select wire:model.live="context" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="general">通常</option>
                <option value="author">作成者の場合に追加</option>
                <option value="assignee">担当者の場合に追加</option>
            </select>
        </div>
    </div>

    @if ($tracker_id && $role_id)
        <form wire:submit="save" class="space-y-8">
            <div class="overflow-x-auto rounded-md border border-gray-200 bg-white p-4">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">ステータス遷移(縦: 現在のステータス、横: 変更後のステータス)</h2>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="px-2 py-1 text-left"></th>
                            @foreach ($this->statuses as $newStatus)
                                <th class="px-2 py-1 text-center text-xs text-gray-500">{{ $newStatus->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->statuses as $oldStatus)
                            <tr wire:key="transition-row-{{ $oldStatus->id }}" class="border-t border-gray-100">
                                <th class="px-2 py-1 text-left text-xs font-medium text-gray-700">{{ $oldStatus->name }}</th>
                                @foreach ($this->statuses as $newStatus)
                                    <td class="px-2 py-1 text-center">
                                        <input type="checkbox"
                                            wire:model="transitions.{{ $oldStatus->id }}-{{ $newStatus->id }}"
                                            class="rounded border-gray-300">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-md border border-gray-200 bg-white p-4">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">フィールドルール(縦: フィールド、横: ステータス)</h2>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="px-2 py-1 text-left"></th>
                            @foreach ($this->statuses as $status)
                                <th class="px-2 py-1 text-center text-xs text-gray-500">{{ $status->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->fields as $fieldKey => $label)
                            <tr wire:key="field-row-{{ $fieldKey }}" class="border-t border-gray-100">
                                <th class="px-2 py-1 text-left text-xs font-medium text-gray-700">{{ $label }}</th>
                                @foreach ($this->statuses as $status)
                                    <td class="px-2 py-1 text-center">
                                        <select wire:model="fieldRules.{{ $fieldKey }}-{{ $status->id }}"
                                            class="rounded-md border-gray-300 text-xs">
                                            <option value="">-</option>
                                            @foreach (\App\Enums\WorkflowFieldRuleType::cases() as $rule)
                                                <option value="{{ $rule->value }}">{{ $rule === \App\Enums\WorkflowFieldRuleType::Required ? '必須' : '読取専用' }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
        </form>
    @else
        <p class="text-sm text-gray-500">トラッカーとロールを選択してください。</p>
    @endif
</div>
