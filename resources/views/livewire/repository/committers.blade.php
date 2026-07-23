<?php

use App\Models\Project;
use App\Models\Repository;
use App\Models\RepositoryCommitter;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Repository $repository;

    public string $committer = '';

    public ?int $userId = null;

    public function mount(Project $project): void
    {
        $this->authorize('manage', [Repository::class, $project]);

        $repository = $project->repository;
        abort_if($repository === null, 404);

        $this->project = $project;
        $this->repository = $repository;
    }

    /**
     * @return Collection<int, RepositoryCommitter>
     */
    #[Computed]
    public function mappings(): Collection
    {
        return $this->repository->committers()->with('user')->orderBy('committer')->get();
    }

    #[Computed]
    public function projectMembers(): Collection
    {
        return $this->project->users;
    }

    public function addMapping(): void
    {
        $data = $this->validate([
            'committer' => [
                'required', 'string', 'max:255',
                Rule::unique('repository_committers', 'committer')->where('repository_id', $this->repository->id),
            ],
            'userId' => ['required', Rule::exists('users', 'id')],
        ]);

        $this->repository->committers()->create([
            'committer' => $data['committer'],
            'user_id' => $data['userId'],
        ]);

        $this->reset('committer', 'userId');
        unset($this->mappings);
    }

    public function deleteMapping(int $mappingId): void
    {
        $this->repository->committers()->where('id', $mappingId)->delete();

        unset($this->mappings);
    }
}; ?>

<div class="max-w-2xl">
    <p class="mb-2 text-sm text-gray-500">
        <a href="{{ route('repository.index', $project) }}" class="text-indigo-600 hover:underline">リポジトリ</a>
    </p>
    <h1 class="text-xl font-semibold text-gray-900 mb-2">コミッターのマッピング</h1>
    <p class="mb-6 text-sm text-gray-500">
        コミットのコミッター情報がユーザーのメールアドレス/ログインIDと一致しない場合、ここで明示的にユーザーを対応付けられます。
        キーワードによる課題のクローズや工数記録(<code>@2h</code>等)は、この対応付けまたは自動照合で解決できたユーザーに対してのみ動作します。
    </p>

    <form wire:submit="addMapping" class="mb-6 flex items-end gap-2 rounded-md border border-gray-200 bg-white p-4">
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700">コミッター文字列</label>
            <input type="text" wire:model="committer" placeholder="例: Jane Doe &lt;jane@old-corp.com&gt;"
                class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm">
            @error('committer') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700">ユーザー</label>
            <select wire:model="userId" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                <option value="">選択してください</option>
                @foreach ($this->projectMembers as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
            </select>
            @error('userId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            追加
        </button>
    </form>

    <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
        @forelse ($this->mappings as $mapping)
            <li class="flex items-center justify-between px-4 py-3" wire:key="committer-mapping-{{ $mapping->id }}">
                <div>
                    <span class="font-mono text-sm text-gray-900">{{ $mapping->committer }}</span>
                    <span class="ml-2 text-xs text-gray-500">→ {{ $mapping->user->name }}</span>
                </div>
                <button wire:click="deleteMapping({{ $mapping->id }})" wire:confirm="この対応付けを削除しますか?"
                    class="text-sm text-red-600 hover:underline">
                    削除
                </button>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-gray-500">対応付けがありません。</li>
        @endforelse
    </ul>
</div>
