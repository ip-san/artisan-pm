<?php

use App\Enums\ScmCapability;
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

    public function mount(Project $project, ?string $repositoryParam = null): void
    {
        $this->authorize('manage', [Repository::class, $project]);

        $repository = $project->resolveRepository($repositoryParam);
        abort_if($repository === null, 404);
        // B'-03: a Filesystem repository has no log — the page doesn't apply.
        abort_unless($repository->supports(ScmCapability::Log), 404);

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
        $this->authorize('manage', [Repository::class, $this->project]);

        // Scoped to project members, not any user in the system — the
        // dropdown only ever offers members, and a crafted request must
        // not be able to map a committer to an arbitrary account outside
        // that set just because Rule::exists('users', 'id') alone would
        // accept any valid id.
        $data = $this->validate([
            'committer' => [
                'required', 'string', 'max:255',
                Rule::unique('repository_committers', 'committer')->where('repository_id', $this->repository->id),
            ],
            'userId' => ['required', Rule::exists('members', 'user_id')->where('project_id', $this->project->id)],
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
        $this->authorize('manage', [Repository::class, $this->project]);

        $this->repository->committers()->where('id', $mappingId)->delete();

        unset($this->mappings);
    }
}; ?>

<div class="max-w-2xl">
    <p class="mb-2 text-sm text-neutral-500">
        <a href="{{ route($repository->routeName('repository.index'), $repository->routeParameters()) }}" class="text-brand-bold hover:underline">{{ __('リポジトリ') }}</a>
    </p>
    <h1 class="text-xl font-semibold text-neutral-900 mb-2">{{ __('コミッターのマッピング') }}</h1>
    <p class="mb-6 text-sm text-neutral-500">
        {{ __('コミットのコミッター情報がユーザーのメールアドレス/ログインIDと一致しない場合、ここで明示的にユーザーを対応付けられます。') }}
        {!! __('キーワードによる課題のクローズや工数記録(:example等)は、この対応付けまたは自動照合で解決できたユーザーに対してのみ動作します。', ['example' => '<code>@2h</code>']) !!}
    </p>

    <form wire:submit="addMapping" class="mb-6 flex items-end gap-2 rounded-md border border-neutral-200 bg-surface p-4">
        <div class="flex-1">
            <label class="block text-sm font-medium text-neutral-700">{{ __('コミッター文字列') }}</label>
            <input type="text" wire:model="committer" placeholder="{{ __('例: :committer', ['committer' => 'Jane Doe <jane@old-corp.com>']) }}"
                class="mt-1 block w-full rounded-md border-neutral-300 font-mono text-sm shadow-sm">
            @error('committer') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>
        <div class="flex-1">
            <label class="block text-sm font-medium text-neutral-700">{{ __('ユーザー') }}</label>
            <select wire:model="userId" class="mt-1 block w-full rounded-md border-neutral-300 text-sm shadow-sm">
                <option value="">{{ __('選択してください') }}</option>
                @foreach ($this->projectMembers as $member)
                    <option value="{{ $member->id }}">{{ $member->displayName() }}</option>
                @endforeach
            </select>
            @error('userId') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
            {{ __('追加') }}
        </button>
    </form>

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-surface">
        @forelse ($this->mappings as $mapping)
            <li class="flex items-center justify-between px-4 py-3" wire:key="committer-mapping-{{ $mapping->id }}">
                <div>
                    <span class="font-mono text-sm text-neutral-900">{{ $mapping->committer }}</span>
                    <span class="ml-2 text-xs text-neutral-500">→ {{ $mapping->user->displayName() }}</span>
                </div>
                <button wire:click="deleteMapping({{ $mapping->id }})" wire:confirm="{{ __('この対応付けを削除しますか?') }}"
                    class="text-sm text-danger-bolder hover:underline">
                    {{ __('削除') }}
                </button>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-neutral-500">{{ __('対応付けがありません。') }}</li>
        @endforelse
    </ul>
</div>
