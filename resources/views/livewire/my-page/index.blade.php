<?php

use App\Enums\QueryType;
use App\Models\Query as SavedQuery;
use App\Models\UserDashboardBlock;
use App\Support\Dashboard\DashboardBlock;
use App\Support\Dashboard\DashboardBlockRegistry;
use App\Support\Dashboard\DashboardBlockRow;
use App\Support\Dashboard\SavedIssueQueryBlock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    /**
     * The starter set a first-time visitor sees — everyone can still
     * remove/rearrange/add from here, this is just what avoids an empty
     * page for a brand new account.
     *
     * @var array<int, string>
     */
    private const array DEFAULT_BLOCK_KEYS = ['assigned_issues', 'reported_issues', 'latest_news'];

    public function mount(): void
    {
        if (UserDashboardBlock::where('user_id', auth()->id())->doesntExist()) {
            foreach (self::DEFAULT_BLOCK_KEYS as $position => $key) {
                UserDashboardBlock::create(['user_id' => auth()->id(), 'block_key' => $key, 'position' => $position]);
            }
        }
    }

    /**
     * @return Collection<int, UserDashboardBlock>
     */
    #[Computed]
    public function activeBlocks(): Collection
    {
        return UserDashboardBlock::where('user_id', auth()->id())->orderBy('position')->get();
    }

    /**
     * @return Collection<int, DashboardBlock>
     */
    #[Computed]
    public function availableBlocks(): Collection
    {
        $activeKeys = $this->activeBlocks->pluck('block_key');

        return app(DashboardBlockRegistry::class)->all()
            ->reject(fn (DashboardBlock $block) => $activeKeys->contains($block->key()));
    }

    /**
     * Saved issue queries this user could add as a block — own plus any
     * shared ones they can see, minus those already on the page. Own vs.
     * public/roles-visibility is pre-filtered in SQL; only the roles
     * check within visibleTo() still needs to run in memory.
     *
     * @return Collection<int, SavedQuery>
     */
    #[Computed]
    public function availableSavedQueries(): Collection
    {
        $activeKeys = $this->activeBlocks->pluck('block_key');

        return SavedQuery::query()
            ->where('type', QueryType::Issue->value)
            ->where(fn ($q) => $q->where('user_id', auth()->id())
                ->orWhere('visibility', 'public')
                ->orWhere('visibility', 'roles'))
            ->with(['roles', 'project'])
            ->orderBy('name')
            ->get()
            ->filter(fn (SavedQuery $query) => $query->visibleTo(auth()->user()))
            ->reject(fn (SavedQuery $query) => $activeKeys->contains(SavedIssueQueryBlock::keyFor($query)))
            ->values();
    }

    /**
     * SavedQuery models for every active issue_query:{id} block, loaded
     * once per render instead of blockRows()/blockLabel() each querying
     * separately for the same block.
     *
     * @return Collection<string, SavedQuery>
     */
    #[Computed]
    public function savedQueriesByBlockKey(): Collection
    {
        $queryIds = $this->activeBlocks
            ->map(fn (UserDashboardBlock $block) => SavedIssueQueryBlock::queryIdFromKey($block->block_key))
            ->filter()
            ->values();

        if ($queryIds->isEmpty()) {
            return collect();
        }

        return $this->savedQueryQuery()
            ->whereIn('id', $queryIds)
            ->with(['project', 'roles'])
            ->get()
            ->mapWithKeys(fn (SavedQuery $query) => [SavedIssueQueryBlock::keyFor($query) => $query]);
    }

    /**
     * @return Builder<SavedQuery>
     */
    private function savedQueryQuery(): Builder
    {
        return SavedQuery::query()->where('type', QueryType::Issue->value);
    }

    public function addBlock(string $key): void
    {
        $queryId = SavedIssueQueryBlock::queryIdFromKey($key);

        if ($queryId !== null) {
            $savedQuery = $this->savedQueryQuery()->find($queryId);
            abort_unless($savedQuery !== null && $savedQuery->visibleTo(auth()->user()), 404);
        } else {
            abort_unless(app(DashboardBlockRegistry::class)->find($key) !== null, 404);
        }

        UserDashboardBlock::firstOrCreate(
            ['user_id' => auth()->id(), 'block_key' => $key],
            ['position' => $this->activeBlocks->count()],
        );

        unset($this->activeBlocks, $this->availableBlocks, $this->availableSavedQueries, $this->savedQueriesByBlockKey);
    }

    public function removeBlock(int $id): void
    {
        UserDashboardBlock::where('user_id', auth()->id())->where('id', $id)->delete();

        unset($this->activeBlocks, $this->availableBlocks, $this->availableSavedQueries, $this->savedQueriesByBlockKey);
    }

    /**
     * wire:sort only reports the moved item's id and its new zero-based
     * position, not the full resulting order — so the new order is
     * reconstructed by removing the moved block from its old spot and
     * reinserting it at the reported position among the rest, then
     * renumbering everyone sequentially.
     */
    public function reorder(int $id, int $position): void
    {
        $blocks = $this->activeBlocks;
        $moved = $blocks->firstWhere('id', $id);

        if ($moved === null) {
            return;
        }

        $reordered = $blocks->reject(fn (UserDashboardBlock $block) => $block->id === $moved->id)->values();
        $reordered->splice($position, 0, [$moved]);

        foreach ($reordered->values() as $index => $block) {
            $block->update(['position' => $index]);
        }

        unset($this->activeBlocks);
    }

    /**
     * @return Collection<int, DashboardBlockRow>
     */
    public function blockRows(string $key): Collection
    {
        if (SavedIssueQueryBlock::queryIdFromKey($key) !== null) {
            return app(SavedIssueQueryBlock::class)->rows($this->savedQueriesByBlockKey->get($key), auth()->user());
        }

        return app(DashboardBlockRegistry::class)->find($key)?->rows(auth()->user()) ?? collect();
    }

    public function blockLabel(string $key): string
    {
        if (SavedIssueQueryBlock::queryIdFromKey($key) !== null) {
            $savedQuery = $this->savedQueriesByBlockKey->get($key);

            return $savedQuery !== null ? "クエリ: {$savedQuery->name}" : 'クエリ: (削除済み)';
        }

        return app(DashboardBlockRegistry::class)->find($key)?->label() ?? $key;
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold text-gray-900 mb-6">マイページ</h1>

    <ul wire:sort="reorder" class="space-y-4">
        @foreach ($this->activeBlocks as $block)
            <li wire:key="block-{{ $block->id }}" wire:sort:item="{{ $block->id }}"
                class="cursor-move rounded-md border border-gray-200 bg-white">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2">
                    <span class="text-sm font-semibold text-gray-900">{{ $this->blockLabel($block->block_key) }}</span>
                    <div wire:sort:ignore>
                        <button wire:click="removeBlock({{ $block->id }})" class="text-xs text-red-600 hover:underline">
                            削除
                        </button>
                    </div>
                </div>
                <ul class="divide-y divide-gray-100">
                    @forelse ($this->blockRows($block->block_key) as $row)
                        <li class="px-4 py-2 text-sm">
                            <a href="{{ $row->url }}" class="text-indigo-600 hover:underline">{{ $row->title }}</a>
                            @if ($row->meta)
                                <span class="text-gray-400">— {{ $row->meta }}</span>
                            @endif
                        </li>
                    @empty
                        <li class="px-4 py-3 text-center text-sm text-gray-500">項目がありません。</li>
                    @endforelse
                </ul>
            </li>
        @endforeach
    </ul>

    @if ($this->availableBlocks->isNotEmpty() || $this->availableSavedQueries->isNotEmpty())
        <div class="mt-6">
            <p class="mb-2 text-sm font-medium text-gray-700">ブロックを追加:</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->availableBlocks as $block)
                    <button wire:click="addBlock('{{ $block->key() }}')"
                        class="rounded-full border border-gray-300 px-3 py-1 text-xs text-gray-600 hover:bg-gray-50">
                        + {{ $block->label() }}
                    </button>
                @endforeach
                @foreach ($this->availableSavedQueries as $savedQuery)
                    <button wire:key="add-query-block-{{ $savedQuery->id }}"
                        wire:click="addBlock('{{ \App\Support\Dashboard\SavedIssueQueryBlock::keyFor($savedQuery) }}')"
                        class="rounded-full border border-indigo-200 px-3 py-1 text-xs text-indigo-600 hover:bg-indigo-50">
                        + クエリ: {{ $savedQuery->name }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
