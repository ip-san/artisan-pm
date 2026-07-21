<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public ?TimeEntry $timeEntry = null;

    public ?int $issue_id = null;

    public ?int $user_id = null;

    public ?int $activity_id = null;

    public string $hours = '';

    public string $spent_on = '';

    public string $comments = '';

    public function mount(Project $project, ?TimeEntry $timeEntry = null): void
    {
        $this->project = $project;

        if ($timeEntry?->exists) {
            $this->authorize('update', $timeEntry);

            $this->timeEntry = $timeEntry;
            $this->issue_id = $timeEntry->issue_id;
            $this->user_id = $timeEntry->user_id;
            $this->activity_id = $timeEntry->activity_id;
            $this->hours = (string) $timeEntry->hours;
            $this->spent_on = $timeEntry->spent_on->toDateString();
            $this->comments = (string) $timeEntry->comments;
        } else {
            $this->authorize('create', [TimeEntry::class, $project]);

            $this->issue_id = request()->integer('issue_id') ?: null;
            $this->user_id = auth()->id();
            $this->activity_id = Enumeration::query()
                ->ofType(EnumerationType::TimeEntryActivity)
                ->where('is_default', true)
                ->first()?->id;
            $this->spent_on = now()->toDateString();
        }
    }

    #[Computed]
    public function activities(): Collection
    {
        return Enumeration::query()->ofType(EnumerationType::TimeEntryActivity)->orderBy('position')->get();
    }

    #[Computed]
    public function projectMembers(): Collection
    {
        return $this->project->users;
    }

    #[Computed]
    public function projectIssues(): Collection
    {
        return $this->project->issues()->orderByDesc('id')->limit(100)->get();
    }

    /**
     * Only members with edit_time_entries may log time on another member's
     * behalf — everyone else's entries are always recorded under their own
     * account, mirroring Redmine's "log time for others" permission.
     */
    #[Computed]
    public function canManageOthers(): bool
    {
        return app(AuthorizationService::class)->can(auth()->user(), 'edit_time_entries', $this->project);
    }

    public function save(): void
    {
        $rules = [
            'issue_id' => ['nullable', Rule::exists('issues', 'id')->where('project_id', $this->project->id)],
            'activity_id' => ['required', Rule::exists('enumerations', 'id')->where('type', EnumerationType::TimeEntryActivity->value)],
            'hours' => ['required', 'numeric', 'min:0.01', 'max:1000'],
            'spent_on' => ['required', 'date'],
            'comments' => ['nullable', 'string'],
        ];

        if ($this->canManageOthers) {
            $rules['user_id'] = ['required', Rule::exists('members', 'user_id')->where('project_id', $this->project->id)];
        }

        $data = $this->validate($rules);

        if (! $this->canManageOthers) {
            $data['user_id'] = auth()->id();
        }

        if ($this->timeEntry) {
            $this->timeEntry->update($data);
        } else {
            $data['project_id'] = $this->project->id;
            TimeEntry::create($data);
        }

        $this->redirect(route('time-entries.index', $this->project), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $timeEntry ? '工数記録を編集' : '工数記録を追加' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">課題</label>
            <select wire:model="issue_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="">なし(プロジェクト全体)</option>
                @foreach ($this->projectIssues as $issue)
                    <option value="{{ $issue->id }}">#{{ $issue->id }} {{ $issue->subject }}</option>
                @endforeach
            </select>
            @error('issue_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        @if ($this->canManageOthers)
            <div>
                <label class="block text-sm font-medium text-gray-700">担当者</label>
                <select wire:model="user_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @foreach ($this->projectMembers as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                    @endforeach
                </select>
                @error('user_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">作業分類</label>
                <select wire:model="activity_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @foreach ($this->activities as $activity)
                        <option value="{{ $activity->id }}">{{ $activity->name }}</option>
                    @endforeach
                </select>
                @error('activity_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">時間</label>
                <input type="number" step="0.01" wire:model="hours"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('hours') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">日付</label>
            <input type="date" wire:model="spent_on"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('spent_on') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">コメント</label>
            <textarea wire:model="comments" rows="3"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            @error('comments') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('time-entries.index', $project) }}"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
