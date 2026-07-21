<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    /** @var array<int, bool> global enumeration_id => active for this project */
    public array $active = [];

    public function mount(Project $project): void
    {
        $this->authorize('update', $project);

        $this->project = $project;

        $overridesByParentId = $project->timeEntryActivityOverrides()->get()->keyBy('parent_id');

        foreach ($this->globalActivities as $activity) {
            $this->active[$activity->id] = $overridesByParentId->get($activity->id)?->active ?? $activity->active;
        }
    }

    /**
     * @return Collection<int, Enumeration>
     */
    #[Computed]
    public function globalActivities(): Collection
    {
        return Enumeration::query()->ofType(EnumerationType::TimeEntryActivity)->whereNull('project_id')->orderBy('position')->get();
    }

    /**
     * Matches Redmine's Project#create_time_entry_activity_if_needed: an
     * override row only exists when this project's state actually differs
     * from the global default (never renames, only toggles active) — so
     * flipping a checkbox back to the global state removes the override
     * instead of leaving a redundant, always-matching row behind.
     */
    public function save(): void
    {
        $this->authorize('update', $this->project);

        $overridesByParentId = $this->project->timeEntryActivityOverrides()->get()->keyBy('parent_id');

        foreach ($this->globalActivities as $activity) {
            $desired = (bool) ($this->active[$activity->id] ?? true);
            $override = $overridesByParentId->get($activity->id);

            if ($desired === $activity->active) {
                $override?->delete();

                continue;
            }

            if ($override) {
                $override->update(['active' => $desired]);
            } else {
                $created = Enumeration::create([
                    'type' => EnumerationType::TimeEntryActivity,
                    'name' => $activity->name,
                    'active' => $desired,
                    'is_default' => false,
                    'project_id' => $this->project->id,
                    'parent_id' => $activity->id,
                ]);
                $created->update(['position' => $activity->position]);
            }
        }

        session()->flash('status', '保存しました。');
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">{{ $project->name }} — 作業分類</h1>

    <p class="mb-4 text-sm text-gray-500">
        このプロジェクトで使用しない作業分類のチェックを外してください。名前の変更はできません(システム全体の値の管理は管理者設定から行います)。
    </p>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="space-y-4">
        <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
            @foreach ($this->globalActivities as $activity)
                <li class="flex items-center justify-between px-4 py-3">
                    <span class="text-sm text-gray-900">
                        {{ $activity->name }}
                        @unless ($activity->active)
                            <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">システム全体で無効</span>
                        @endunless
                    </span>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="active.{{ $activity->id }}" class="rounded border-gray-300">
                        有効
                    </label>
                </li>
            @endforeach
        </ul>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('projects.show', $project) }}"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                戻る
            </a>
        </div>
    </form>
</div>
