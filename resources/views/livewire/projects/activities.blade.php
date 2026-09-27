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
        $this->authorize('manageActivities', $project);

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
        $this->authorize('manageActivities', $this->project);

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

        session()->flash('status', __('保存しました。'));
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">{{ __(':project — 作業分類', ['project' => $project->name]) }}</h1>

    <p class="mb-4 text-sm text-neutral-500">
        {{ __('このプロジェクトで使用しない作業分類のチェックを外してください。名前の変更はできません(システム全体の値の管理は管理者設定から行います)。') }}
    </p>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-success-subtlest p-3 text-sm text-success-bold">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="space-y-4">
        <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-surface">
            @foreach ($this->globalActivities as $activity)
                <li class="flex items-center justify-between px-4 py-3">
                    <span class="text-sm text-neutral-900">
                        {{ $activity->name }}
                        @unless ($activity->active)
                            <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ __('システム全体で無効') }}</span>
                        @endunless
                    </span>
                    <label class="flex items-center gap-2 text-sm text-neutral-700">
                        <input type="checkbox" wire:model="active.{{ $activity->id }}" class="rounded border-neutral-300">
                        {{ __('有効') }}
                    </label>
                </li>
            @endforeach
        </ul>

        <div class="flex gap-3">
            <button type="submit" class="btn btn-primary">
                {{ __('保存') }}
            </button>
            <a href="{{ route('projects.show', $project) }}"
                class="btn btn-secondary">
                {{ __('戻る') }}
            </a>
        </div>
    </form>
</div>
