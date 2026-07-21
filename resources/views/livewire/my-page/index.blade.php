<?php

use App\Models\UserDashboardBlock;
use App\Support\Dashboard\DashboardBlock;
use App\Support\Dashboard\DashboardBlockRegistry;
use App\Support\Dashboard\DashboardBlockRow;
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

    public function addBlock(string $key): void
    {
        abort_unless(app(DashboardBlockRegistry::class)->find($key) !== null, 404);

        UserDashboardBlock::firstOrCreate(
            ['user_id' => auth()->id(), 'block_key' => $key],
            ['position' => $this->activeBlocks->count()],
        );

        unset($this->activeBlocks, $this->availableBlocks);
    }

    public function removeBlock(int $id): void
    {
        UserDashboardBlock::where('user_id', auth()->id())->where('id', $id)->delete();

        unset($this->activeBlocks, $this->availableBlocks);
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
        return app(DashboardBlockRegistry::class)->find($key)?->rows(auth()->user()) ?? collect();
    }

    public function blockLabel(string $key): string
    {
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

    @if ($this->availableBlocks->isNotEmpty())
        <div class="mt-6">
            <p class="mb-2 text-sm font-medium text-gray-700">ブロックを追加:</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->availableBlocks as $block)
                    <button wire:click="addBlock('{{ $block->key() }}')"
                        class="rounded-full border border-gray-300 px-3 py-1 text-xs text-gray-600 hover:bg-gray-50">
                        + {{ $block->label() }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
