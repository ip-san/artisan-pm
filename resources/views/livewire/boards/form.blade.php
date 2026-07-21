<?php

use App\Models\Board;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public ?Board $board = null;

    public string $name = '';

    public string $description = '';

    public ?int $parent_id = null;

    public function mount(Project $project, ?Board $board = null): void
    {
        $this->project = $project;

        if ($board?->exists) {
            $this->authorize('update', $board);

            $this->board = $board;
            $this->name = $board->name;
            $this->description = (string) $board->description;
            $this->parent_id = $board->parent_id;
        } else {
            $this->authorize('create', [Board::class, $project]);
        }
    }

    /**
     * Excludes the board being edited itself and its own descendants,
     * which would otherwise let a board become its own ancestor —
     * same approach as the wiki page parent picker.
     *
     * @return Collection<int, Board>
     */
    #[Computed]
    public function availableParents(): Collection
    {
        $boards = $this->project->boards()->orderBy('position')->get();

        if ($this->board === null) {
            return $boards;
        }

        $excluded = collect([$this->board->id]);
        $frontier = $excluded;

        while ($frontier->isNotEmpty()) {
            $children = $boards->whereIn('parent_id', $frontier)->pluck('id');
            $frontier = $children->diff($excluded);
            $excluded = $excluded->merge($children);
        }

        return $boards->reject(fn (Board $candidate) => $excluded->contains($candidate->id))->values();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', Rule::in($this->availableParents->pluck('id')->all())],
        ]);

        if ($this->board) {
            $this->board->update($data);
        } else {
            $data['project_id'] = $this->project->id;
            Board::create($data);
        }

        $this->redirect(route('boards.index', $this->project), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $board ? 'フォーラムを編集' : '新規フォーラム' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">説明</label>
            <textarea wire:model="description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm"></textarea>
            @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">親フォーラム</label>
            <select wire:model="parent_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="">なし</option>
                @foreach ($this->availableParents as $candidate)
                    <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                @endforeach
            </select>
            @error('parent_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('boards.index', $project) }}"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
