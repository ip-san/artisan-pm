<?php

use App\Models\CustomField;
use App\Models\IssueStatus;
use App\Models\Tracker;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?Tracker $tracker = null;

    public string $name = '';

    public string $description = '';

    public ?int $default_status_id = null;

    /** @var array<int, string> */
    public array $disabled_core_fields = [];

    public bool $private_by_default = false;

    public bool $is_in_roadmap = true;

    public ?int $copyFromTrackerId = null;

    public function mount(?Tracker $tracker = null): void
    {
        if ($tracker?->exists) {
            $this->authorize('update', $tracker);

            $this->tracker = $tracker;
            $this->name = $tracker->name;
            $this->description = (string) $tracker->description;
            $this->default_status_id = $tracker->default_status_id;
            $this->disabled_core_fields = $tracker->disabled_core_fields ?? [];
            $this->private_by_default = $tracker->private_by_default;
            $this->is_in_roadmap = $tracker->is_in_roadmap;
        } else {
            $this->authorize('create', Tracker::class);
        }
    }

    /**
     * @return Collection<int, IssueStatus>
     */
    #[Computed]
    public function statuses(): Collection
    {
        return IssueStatus::query()->orderBy('position')->get();
    }

    /**
     * @return Collection<int, Tracker>
     */
    #[Computed]
    public function copySourceTrackers(): Collection
    {
        return Tracker::query()->orderBy('position')->get();
    }

    /**
     * Prefills the form from the chosen tracker, matching Redmine's own
     * "copy from" convenience on the new-tracker form. Only attributes are
     * prefilled here; project and custom field associations are copied on
     * save() since they need the new tracker's id to exist first.
     */
    public function updatedCopyFromTrackerId(): void
    {
        $source = $this->copyFromTrackerId !== null ? Tracker::find($this->copyFromTrackerId) : null;

        if ($source === null) {
            return;
        }

        $this->description = (string) $source->description;
        $this->default_status_id = $source->default_status_id;
        $this->disabled_core_fields = $source->disabled_core_fields ?? [];
        $this->private_by_default = $source->private_by_default;
        $this->is_in_roadmap = $source->is_in_roadmap;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('trackers', 'name')->ignore($this->tracker?->id)],
            'description' => ['nullable', 'string'],
            'default_status_id' => ['nullable', 'exists:issue_statuses,id'],
            'disabled_core_fields' => ['array'],
            'disabled_core_fields.*' => [Rule::in(array_keys(Tracker::DISABLABLE_CORE_FIELDS))],
            'private_by_default' => ['boolean'],
            'is_in_roadmap' => ['boolean'],
            'copyFromTrackerId' => ['nullable', 'exists:trackers,id'],
        ]);

        $copyFromTrackerId = $data['copyFromTrackerId'];
        unset($data['copyFromTrackerId']);

        if ($this->tracker) {
            $this->tracker->update($data);
        } else {
            $tracker = Tracker::create($data);

            $source = $copyFromTrackerId !== null ? Tracker::find($copyFromTrackerId) : null;

            if ($source !== null) {
                $tracker->projects()->sync($source->projects()->pluck('projects.id'));

                CustomField::query()
                    ->whereHas('trackers', fn ($query) => $query->where('trackers.id', $source->id))
                    ->get()
                    ->each(fn (CustomField $field) => $field->trackers()->attach($tracker->id));
            }
        }

        $this->redirect(route('trackers.index'), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $tracker ? 'トラッカーを編集' : '新規トラッカー' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        @unless ($tracker)
            <div>
                <label class="block text-sm font-medium text-gray-700">コピー元トラッカー</label>
                <select wire:model.live="copyFromTrackerId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    <option value="">なし</option>
                    @foreach ($this->copySourceTrackers as $source)
                        <option value="{{ $source->id }}">{{ $source->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">選択すると、説明・既定ステータス・非表示フィールド・非公開既定値・ロードマップ対象フラグをコピーします。保存時にプロジェクトへの割り当てとカスタムフィールドの紐付けもコピーされます。</p>
                @error('copyFromTrackerId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endunless

        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">説明</label>
            <textarea wire:model="description" rows="3"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">既定のステータス(新規課題作成時)</label>
            <select wire:model="default_status_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="">なし(全体の先頭ステータスを使用)</option>
                @foreach ($this->statuses as $status)
                    <option value="{{ $status->id }}">{{ $status->name }}</option>
                @endforeach
            </select>
            @error('default_status_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <span class="block text-sm font-medium text-gray-700 mb-2">非表示にするフィールド(このトラッカーの課題フォームから除外)</span>
            <div class="grid grid-cols-2 gap-2">
                @foreach (\App\Models\Tracker::DISABLABLE_CORE_FIELDS as $key => $label)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="disabled_core_fields" value="{{ $key }}" class="rounded border-gray-300">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
            @error('disabled_core_fields.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model="private_by_default" class="rounded border-gray-300">
            このトラッカーの新規課題は既定で非公開にする
        </label>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model="is_in_roadmap" class="rounded border-gray-300">
            ロードマップに表示する
        </label>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('trackers.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
