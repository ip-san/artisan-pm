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
use Illuminate\Validation\Rule;
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
     * assignee. The grid's first row, "new issue" (key 0 — stored with a
     * null old_status_id), lists the statuses a new issue may start in.
     *
     * @var 'general'|'author'|'assignee'
     */
    public string $context = 'general';

    /** @var array<string, bool> keyed "{old_status_id}-{new_status_id}" */
    public array $transitions = [];

    /** @var array<string, string> keyed "{field_name}-{status_id}" => ''|'required'|'read_only' */
    public array $fieldRules = [];

    /**
     * Matches Redmine's "used statuses only" checkbox on the workflow
     * admin screen: once a tracker has any transitions defined, the grid
     * only shows the statuses actually referenced by them instead of
     * every status in the system. Defaults on, same as Redmine.
     */
    public bool $usedStatusesOnly = true;

    /**
     * A tracker id, `any` (Redmine's "same as target": each target pair uses
     * its own tracker as the source), or null while nothing is chosen.
     */
    public int|string|null $copySourceTrackerId = null;

    /**
     * A role id, `any` ("same as target": each target pair uses its own
     * role as the source), or null while nothing is chosen.
     */
    public int|string|null $copySourceRoleId = null;

    /** @var array<int, int> */
    public array $copyTargetTrackerIds = [];

    /** @var array<int, int> */
    public array $copyTargetRoleIds = [];

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
        if ($this->tracker_id !== null && $this->usedStatusesOnly) {
            $usedStatusIds = WorkflowTransition::query()
                ->where('tracker_id', $this->tracker_id)
                ->whereColumn('old_status_id', '!=', 'new_status_id')
                ->get(['old_status_id', 'new_status_id'])
                ->flatMap(fn (WorkflowTransition $transition) => [$transition->old_status_id, $transition->new_status_id])
                ->filter()
                ->unique();

            if ($usedStatusIds->isNotEmpty()) {
                return IssueStatus::query()->whereIn('id', $usedStatusIds)->orderBy('position')->get();
            }
        }

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
            ->get()
            ->mapWithKeys(fn (WorkflowTransition $t) => [($t->old_status_id ?? 0).'-'.$t->new_status_id => true])
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
                ->delete();

            foreach ($this->transitions as $key => $checked) {
                if (! $checked) {
                    continue;
                }

                [$oldStatusId, $newStatusId] = explode('-', $key);

                WorkflowTransition::create([
                    'tracker_id' => $this->tracker_id,
                    'role_id' => $this->role_id,
                    'old_status_id' => (int) $oldStatusId === 0 ? null : (int) $oldStatusId,
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

    /**
     * Copies every workflow_transitions/workflow_field_rules row for a
     * source (tracker, role) pair onto every (target tracker × target role)
     * combination — Redmine's WorkflowRule.copy. Either source side may be
     * `any` ("same as target"), so a single tracker's workflow can be copied
     * role by role, or one role's across every tracker, but not both at
     * once. Each target pair's existing rows are replaced, not merged, as in
     * Redmine's copy_one, and a pair whose source equals its target is
     * skipped. Targets must be chosen explicitly (Redmine's controller
     * rejects an empty target selection too).
     */
    public function copyWorkflow(): void
    {
        $this->authorize('manage', WorkflowTransition::class);

        $data = $this->validate([
            'copySourceTrackerId' => ['required', Rule::in(['any', ...Tracker::query()->pluck('id')->all()])],
            'copySourceRoleId' => ['required', Rule::in(['any', ...$this->roles->pluck('id')->all()])],
            'copyTargetTrackerIds' => ['required', 'array', 'min:1'],
            'copyTargetTrackerIds.*' => ['exists:trackers,id'],
            'copyTargetRoleIds' => ['required', 'array', 'min:1'],
            'copyTargetRoleIds.*' => [Rule::in($this->roles->pluck('id')->all())],
        ]);

        $sourceTrackerId = (string) $data['copySourceTrackerId'] === 'any' ? null : (int) $data['copySourceTrackerId'];
        $sourceRoleId = (string) $data['copySourceRoleId'] === 'any' ? null : (int) $data['copySourceRoleId'];

        if ($sourceTrackerId === null && $sourceRoleId === null) {
            $this->addError('copySourceTrackerId', 'コピー元のトラッカーとロールの少なくとも一方を指定してください。');

            return;
        }

        DB::transaction(function () use ($data, $sourceTrackerId, $sourceRoleId) {
            foreach ($data['copyTargetTrackerIds'] as $targetTrackerId) {
                foreach ($data['copyTargetRoleIds'] as $targetRoleId) {
                    $targetTrackerId = (int) $targetTrackerId;
                    $targetRoleId = (int) $targetRoleId;

                    $pairSourceTrackerId = $sourceTrackerId ?? $targetTrackerId;
                    $pairSourceRoleId = $sourceRoleId ?? $targetRoleId;

                    if ($pairSourceTrackerId === $targetTrackerId && $pairSourceRoleId === $targetRoleId) {
                        continue;
                    }

                    $this->copyWorkflowPair($pairSourceTrackerId, $pairSourceRoleId, $targetTrackerId, $targetRoleId);
                }
            }
        });

        session()->flash('status', 'ワークフローをコピーしました。');
    }

    private function copyWorkflowPair(int $sourceTrackerId, int $sourceRoleId, int $targetTrackerId, int $targetRoleId): void
    {
        WorkflowTransition::query()->where('tracker_id', $targetTrackerId)->where('role_id', $targetRoleId)->delete();
        WorkflowFieldRule::query()->where('tracker_id', $targetTrackerId)->where('role_id', $targetRoleId)->delete();

        WorkflowTransition::query()
            ->where('tracker_id', $sourceTrackerId)
            ->where('role_id', $sourceRoleId)
            ->get()
            ->each(function (WorkflowTransition $transition) use ($targetTrackerId, $targetRoleId) {
                WorkflowTransition::create([
                    'tracker_id' => $targetTrackerId,
                    'role_id' => $targetRoleId,
                    'old_status_id' => $transition->old_status_id,
                    'new_status_id' => $transition->new_status_id,
                    'author' => $transition->author,
                    'assignee' => $transition->assignee,
                ]);
            });

        WorkflowFieldRule::query()
            ->where('tracker_id', $sourceTrackerId)
            ->where('role_id', $sourceRoleId)
            ->get()
            ->each(function (WorkflowFieldRule $rule) use ($targetTrackerId, $targetRoleId) {
                WorkflowFieldRule::create([
                    'tracker_id' => $targetTrackerId,
                    'role_id' => $targetRoleId,
                    'status_id' => $rule->status_id,
                    'field_name' => $rule->field_name,
                    'rule' => $rule->rule,
                    'author' => $rule->author,
                    'assignee' => $rule->assignee,
                ]);
            });
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">ワークフロー管理</h1>

    <div class="mb-6 grid grid-cols-3 gap-4 rounded-md border border-neutral-200 bg-white p-4">
        <div>
            <label class="block text-sm font-medium text-neutral-700">トラッカー</label>
            <select wire:model.live="tracker_id" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <option value="">選択してください</option>
                @foreach ($this->trackers as $tracker)
                    <option value="{{ $tracker->id }}">{{ $tracker->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-neutral-700">ロール</label>
            <select wire:model.live="role_id" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <option value="">選択してください</option>
                @foreach ($this->roles as $role)
                    <option value="{{ $role->id }}">{{ $role->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-neutral-700">適用対象</label>
            <select wire:model.live="context" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                <option value="general">通常</option>
                <option value="author">作成者の場合に追加</option>
                <option value="assignee">担当者の場合に追加</option>
            </select>
        </div>
    </div>

    <label class="mb-6 flex items-center gap-2 text-sm text-neutral-700">
        <input type="checkbox" wire:model.live="usedStatusesOnly" class="rounded border-neutral-300">
        使用中のステータスのみ表示(選択したトラッカーで遷移が定義済みのステータスに限定)
    </label>

    @if ($tracker_id && $role_id)
        <form wire:submit="save" class="space-y-8">
            <div class="overflow-x-auto rounded-md border border-neutral-200 bg-white p-4">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">ステータス遷移(縦: 現在のステータス、横: 変更後のステータス)</h2>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="px-2 py-1 text-left"></th>
                            @foreach ($this->statuses as $newStatus)
                                <th class="px-2 py-1 text-center text-xs text-neutral-500">{{ $newStatus->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr wire:key="transition-row-new" class="border-t border-neutral-100 bg-neutral-50" data-new-issue-row>
                            <th class="px-2 py-1 text-left text-xs font-medium text-neutral-700">(新規課題)</th>
                            @foreach ($this->statuses as $newStatus)
                                <td class="px-2 py-1 text-center">
                                    <input type="checkbox" wire:model="transitions.0-{{ $newStatus->id }}" class="rounded border-neutral-300">
                                </td>
                            @endforeach
                        </tr>
                        @foreach ($this->statuses as $oldStatus)
                            <tr wire:key="transition-row-{{ $oldStatus->id }}" class="border-t border-neutral-100">
                                <th class="px-2 py-1 text-left text-xs font-medium text-neutral-700">{{ $oldStatus->name }}</th>
                                @foreach ($this->statuses as $newStatus)
                                    <td class="px-2 py-1 text-center">
                                        <input type="checkbox"
                                            wire:model="transitions.{{ $oldStatus->id }}-{{ $newStatus->id }}"
                                            class="rounded border-neutral-300">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="overflow-x-auto rounded-md border border-neutral-200 bg-white p-4">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900">フィールドルール(縦: フィールド、横: ステータス)</h2>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="px-2 py-1 text-left"></th>
                            @foreach ($this->statuses as $status)
                                <th class="px-2 py-1 text-center text-xs text-neutral-500">{{ $status->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->fields as $fieldKey => $label)
                            <tr wire:key="field-row-{{ $fieldKey }}" class="border-t border-neutral-100">
                                <th class="px-2 py-1 text-left text-xs font-medium text-neutral-700">{{ $label }}</th>
                                @foreach ($this->statuses as $status)
                                    <td class="px-2 py-1 text-center">
                                        <select wire:model="fieldRules.{{ $fieldKey }}-{{ $status->id }}"
                                            class="rounded-md border-neutral-300 text-xs">
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

            <button type="submit" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                保存
            </button>
        </form>
    @else
        <p class="text-sm text-neutral-500">トラッカーとロールを選択してください。</p>
    @endif

    <div class="mt-10 rounded-md border border-neutral-200 bg-white p-4">
        <h2 class="mb-4 text-sm font-semibold text-neutral-900">ワークフローをコピー</h2>

        <form wire:submit="copyWorkflow" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-neutral-700">コピー元トラッカー</label>
                    <select wire:model="copySourceTrackerId" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                        <option value="">選択してください</option>
                        <option value="any">--- コピー先と同じ ---</option>
                        @foreach ($this->trackers as $tracker)
                            <option value="{{ $tracker->id }}">{{ $tracker->name }}</option>
                        @endforeach
                    </select>
                    @error('copySourceTrackerId') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-neutral-700">コピー元ロール</label>
                    <select wire:model="copySourceRoleId" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                        <option value="">選択してください</option>
                        <option value="any">--- コピー先と同じ ---</option>
                        @foreach ($this->roles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                    @error('copySourceRoleId') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="block text-sm font-medium text-neutral-700 mb-1">コピー先トラッカー</span>
                    <div class="max-h-40 space-y-1 overflow-y-auto rounded-md border border-neutral-200 p-2">
                        @foreach ($this->trackers as $tracker)
                            <label class="flex items-center gap-2 text-sm text-neutral-700">
                                <input type="checkbox" wire:model="copyTargetTrackerIds" value="{{ $tracker->id }}" class="rounded border-neutral-300">
                                {{ $tracker->name }}
                            </label>
                        @endforeach
                    </div>
                    @error('copyTargetTrackerIds') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <div>
                    <span class="block text-sm font-medium text-neutral-700 mb-1">コピー先ロール</span>
                    <div class="max-h-40 space-y-1 overflow-y-auto rounded-md border border-neutral-200 p-2">
                        @foreach ($this->roles as $role)
                            <label class="flex items-center gap-2 text-sm text-neutral-700">
                                <input type="checkbox" wire:model="copyTargetRoleIds" value="{{ $role->id }}" class="rounded border-neutral-300">
                                {{ $role->name }}
                            </label>
                        @endforeach
                    </div>
                    @error('copyTargetRoleIds') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
            </div>

            <button type="submit" wire:confirm="コピー先の既存ワークフロー設定は上書きされます。よろしいですか?"
                class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                コピー
            </button>
        </form>
    </div>
</div>
