<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\TimeEntryService;
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

    /** The project the entry is (or will be) logged in; editing may move it. */
    public ?int $project_id = null;

    public ?int $issue_id = null;

    public ?int $user_id = null;

    public ?int $activity_id = null;

    public string $hours = '';

    public string $spent_on = '';

    public string $comments = '';

    public function mount(Project $project, ?TimeEntry $timeEntry = null): void
    {
        $this->project = $project;
        $this->project_id = $project->id;

        if ($timeEntry?->exists) {
            // {timeEntry} is a plain implicit binding by id, independent of the {project} route segment.
            abort_unless($timeEntry->project_id === $project->id, 404);

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
            $this->activity_id = $project->defaultActivityId(auth()->user());
            $this->spent_on = now()->toDateString();
        }
    }

    /**
     * Where the entry lands: the route's project unless an edit picked
     * another one, which must be a project the user may log time in
     * (Redmine's Project.allowed_to(:log_time) list on TimelogController).
     */
    #[Computed]
    public function targetProject(): Project
    {
        if ($this->project_id === null || $this->project_id === $this->project->id) {
            return $this->project;
        }

        return $this->moveTargets->firstWhere('id', $this->project_id) ?? $this->project;
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function moveTargets(): Collection
    {
        return Project::query()->orderBy('name')->get()
            ->filter(fn (Project $candidate) => $candidate->is($this->project) || auth()->user()->can('create', [TimeEntry::class, $candidate]))
            ->values();
    }

    /**
     * Moving to another project drops what only made sense in the old one:
     * the issue (an entry's issue must belong to its project) and an
     * activity the new project does not offer.
     */
    public function updatedProjectId(): void
    {
        unset($this->targetProject);

        if ($this->issue_id !== null && ! $this->targetProject->issues()->whereKey($this->issue_id)->exists()) {
            $this->issue_id = null;
        }

        if (! $this->activities->contains('id', $this->activity_id)) {
            $this->activity_id = $this->targetProject->defaultActivityId(auth()->user());
        }

        if ($this->user_id !== null && ! $this->targetProject->loadMissing('users')->users->contains('id', $this->user_id)) {
            $this->user_id = auth()->id();
        }
    }

    /**
     * This project's effective TimeEntryActivity set — matches Redmine's
     * Project#activities, so an activity a project has deactivated (see
     * projects.activities) no longer appears here even though it's still
     * globally defined. Inactive (system-wide) ones are still included,
     * matching this form's prior behavior of not filtering by `active` at
     * all — narrowing that is a separate, pre-existing gap out of scope
     * here.
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->targetProject->activities(includeInactive: true);
    }

    #[Computed]
    public function projectMembers(): Collection
    {
        return $this->targetProject->loadMissing('users')->users;
    }

    /**
     * Limited to the 100 most recent issues so the dropdown stays small on
     * large projects, but the currently selected issue (e.g. arrived at via
     * an issue's "log time" link, or already set on an entry being edited)
     * is always included even if it falls outside that window — otherwise
     * the select would silently show no match for it.
     */
    #[Computed]
    public function projectIssues(): Collection
    {
        $issues = $this->targetProject->issues()->orderByDesc('id')->limit(100)->get();

        if ($this->issue_id !== null && ! $issues->contains('id', $this->issue_id)) {
            $selected = $this->targetProject->issues()->find($this->issue_id);

            if ($selected !== null) {
                $issues->prepend($selected);
            }
        }

        return $issues;
    }

    /**
     * Only members with edit_time_entries may log time on another member's
     * behalf — everyone else's entries are always recorded under their own
     * account, mirroring Redmine's "log time for others" permission.
     */
    #[Computed]
    public function canManageOthers(): bool
    {
        return app(AuthorizationService::class)->can(auth()->user(), 'edit_time_entries', $this->targetProject);
    }

    public function save(): void
    {
        $target = $this->targetProject;

        // A tampered project id is not among the moveTargets; treating it as
        // "no move" would silently save elsewhere, so refuse it outright.
        abort_if($this->project_id !== null && $this->project_id !== $target->id, 403);

        $rules = [
            'issue_id' => ['nullable', Rule::exists('issues', 'id')->where('project_id', $target->id)],
            'activity_id' => ['required', Rule::in($this->activities->pluck('id')->all())],
            'hours' => ['required', 'numeric', 'min:0', 'max:1000'],
            'spent_on' => ['required', 'date'],
            'comments' => ['nullable', 'string'],
        ];

        if ($this->canManageOthers) {
            $rules['user_id'] = ['required', Rule::exists('members', 'user_id')->where('project_id', $target->id)];
        }

        $data = $this->validate($rules);

        if (! $this->canManageOthers) {
            $data['user_id'] = auth()->id();
        }

        if ($this->timeEntry) {
            if ($target->id !== $this->timeEntry->project_id) {
                $this->authorize('create', [TimeEntry::class, $target]);
                $data['project_id'] = $target->id;
            }

            app(TimeEntryService::class)->update($this->timeEntry, $data);
        } else {
            $data['project_id'] = $target->id;
            app(TimeEntryService::class)->create($data);
        }

        $this->redirect(route('time-entries.index', $target), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $timeEntry ? '工数記録を編集' : '工数記録を追加' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        @if ($timeEntry && $this->moveTargets->count() > 1)
            <div>
                <label class="block text-sm font-medium text-gray-700">プロジェクト</label>
                <select wire:model.live="project_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @foreach ($this->moveTargets as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                    @endforeach
                </select>
                @error('project_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endif

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
