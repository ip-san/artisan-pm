<?php

use App\Enums\QueryType;
use App\Models\Query as SavedQuery;
use App\Models\UserDashboardBlock;
use App\Support\Dashboard\ConfigurableDashboardBlock;
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

    /** Redmine's max_occurs for the issue query block: one saved query may be placed this often. */
    private const int MAX_QUERY_BLOCK_OCCURRENCES = 3;

    /** The block whose settings form is open. */
    public ?int $settingsBlockId = null;

    /** @var array<string, mixed> */
    public array $settingsForm = [];

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
        $fullKeys = $this->activeBlocks->countBy('block_key')
            ->filter(fn (int $count) => $count >= self::MAX_QUERY_BLOCK_OCCURRENCES)->keys();

        return SavedQuery::query()
            ->where('type', QueryType::Issue->value)
            ->where(fn ($q) => $q->where('user_id', auth()->id())
                ->orWhere('visibility', 'public')
                ->orWhere('visibility', 'roles'))
            ->with(['roles', 'project'])
            ->orderBy('name')
            ->get()
            ->filter(fn (SavedQuery $query) => $query->visibleTo(auth()->user()))
            ->reject(fn (SavedQuery $query) => $fullKeys->contains(SavedIssueQueryBlock::keyFor($query)))
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

        $occurrences = UserDashboardBlock::where('user_id', auth()->id())->where('block_key', $key)->count();
        $maxOccurrences = $queryId !== null ? self::MAX_QUERY_BLOCK_OCCURRENCES : 1;

        if ($occurrences < $maxOccurrences) {
            UserDashboardBlock::create(['user_id' => auth()->id(), 'block_key' => $key, 'position' => $this->activeBlocks->count()]);
        }

        unset($this->activeBlocks, $this->availableBlocks, $this->availableSavedQueries, $this->savedQueriesByBlockKey);
    }

    public function removeBlock(int $id): void
    {
        UserDashboardBlock::where('user_id', auth()->id())->where('id', $id)->delete();

        if ($this->settingsBlockId === $id) {
            $this->closeSettings();
        }

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
     * @param  array<string, mixed>  $settings  the block's own settings
     * @return Collection<int, DashboardBlockRow>
     */
    public function blockRows(string $key, array $settings = []): Collection
    {
        if (SavedIssueQueryBlock::queryIdFromKey($key) !== null) {
            return app(SavedIssueQueryBlock::class)->rows($this->savedQueriesByBlockKey->get($key), auth()->user(), $settings);
        }

        $block = app(DashboardBlockRegistry::class)->find($key);

        return $block instanceof ConfigurableDashboardBlock
            ? $block->rowsWithSettings(auth()->user(), $settings)
            : ($block?->rows(auth()->user()) ?? collect());
    }

    /**
     * The settings form of a block, or [] when it has none.
     *
     * @return array<string, array{label: string, type: string, options?: array<string, string>, placeholder?: string}>
     */
    public function settingFieldsFor(string $key): array
    {
        if (SavedIssueQueryBlock::queryIdFromKey($key) !== null) {
            $sorts = [];

            foreach (SavedIssueQueryBlock::SORTS as $column => $label) {
                $sorts["{$column}:asc"] = "{$label}(昇順)";
                $sorts["{$column}:desc"] = "{$label}(降順)";
            }

            return [
                'columns' => ['label' => '表示する項目', 'type' => 'columns', 'options' => SavedIssueQueryBlock::COLUMNS],
                'sort' => ['label' => '並び順(空欄はクエリの並び順)', 'type' => 'select', 'options' => $sorts],
            ];
        }

        $block = app(DashboardBlockRegistry::class)->find($key);

        return $block instanceof ConfigurableDashboardBlock ? $block->settingFields() : [];
    }

    public function openSettings(int $id): void
    {
        $block = $this->activeBlocks->firstWhere('id', $id);
        abort_if($block === null, 404);

        $this->settingsBlockId = $block->id;
        $this->settingsForm = $block->settings ?? [];
    }

    public function closeSettings(): void
    {
        $this->reset('settingsBlockId', 'settingsForm');
    }

    public function saveSettings(): void
    {
        $block = $this->activeBlocks->firstWhere('id', $this->settingsBlockId);
        abort_if($block === null, 404);

        $key = $block->block_key;
        $input = $this->settingsForm;

        $settings = SavedIssueQueryBlock::queryIdFromKey($key) !== null
            ? SavedIssueQueryBlock::normalizeSettings($input)
            : (app(DashboardBlockRegistry::class)->find($key) instanceof ConfigurableDashboardBlock
                ? app(DashboardBlockRegistry::class)->find($key)->normalizeSettings($input)
                : []);

        $block->update(['settings' => $settings === [] ? null : $settings]);

        $this->closeSettings();
        unset($this->activeBlocks);
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
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">マイページ</h1>

    <ul wire:sort="reorder" class="space-y-4">
        @foreach ($this->activeBlocks as $block)
            <li wire:key="block-{{ $block->id }}" wire:sort:item="{{ $block->id }}"
                class="cursor-move rounded-md border border-neutral-200 bg-white">
                <div class="flex items-center justify-between border-b border-neutral-100 px-4 py-2">
                    <span class="text-sm font-semibold text-neutral-900">{{ $this->blockLabel($block->block_key) }}</span>
                    <div wire:sort:ignore class="flex items-center gap-3">
                        @if ($this->settingFieldsFor($block->block_key) !== [])
                            <button wire:click="openSettings({{ $block->id }})" data-block-settings class="text-xs text-neutral-600 hover:underline">
                                設定
                            </button>
                        @endif
                        <button wire:click="removeBlock({{ $block->id }})" class="text-xs text-danger-bolder hover:underline">
                            削除
                        </button>
                    </div>
                </div>
                @if ($settingsBlockId === $block->id)
                    <form wire:submit="saveSettings" wire:sort:ignore data-block-settings-form class="space-y-3 border-b border-neutral-100 bg-neutral-50 px-4 py-3">
                        @foreach ($this->settingFieldsFor($block->block_key) as $name => $field)
                            <div wire:key="setting-{{ $block->id }}-{{ $name }}">
                                <span class="block text-xs font-medium text-neutral-700">{{ $field['label'] }}</span>
                                @if ($field['type'] === 'number')
                                    <input type="number" min="1" wire:model="settingsForm.{{ $name }}" placeholder="{{ $field['placeholder'] ?? '' }}"
                                        class="mt-1 w-32 rounded-md border-neutral-300 text-sm">
                                @elseif ($field['type'] === 'select')
                                    <select wire:model="settingsForm.{{ $name }}" class="mt-1 rounded-md border-neutral-300 text-sm">
                                        <option value=""></option>
                                        @foreach ($field['options'] as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <div class="mt-1 flex flex-wrap gap-3">
                                        @foreach ($field['options'] as $value => $label)
                                            <label class="flex items-center gap-1 text-xs text-neutral-700">
                                                <input type="checkbox" wire:model="settingsForm.{{ $name }}" value="{{ $value }}" class="rounded border-neutral-300">
                                                {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        <div class="flex gap-3">
                            <button type="submit" class="rounded-md bg-brand-bold px-3 py-1 text-xs font-medium text-white hover:bg-brand">保存</button>
                            <button type="button" wire:click="closeSettings" class="text-xs text-neutral-600 hover:underline">キャンセル</button>
                        </div>
                    </form>
                @endif
                <ul class="divide-y divide-neutral-100">
                    @forelse ($this->blockRows($block->block_key, $block->settings ?? []) as $row)
                        <li class="px-4 py-2 text-sm">
                            <a href="{{ $row->url }}" class="text-brand-bold hover:underline">{{ $row->title }}</a>
                            @if ($row->meta)
                                <span class="text-neutral-400">— {{ $row->meta }}</span>
                            @endif
                        </li>
                    @empty
                        <li class="px-4 py-3 text-center text-sm text-neutral-500">項目がありません。</li>
                    @endforelse
                </ul>
            </li>
        @endforeach
    </ul>

    @if ($this->availableBlocks->isNotEmpty() || $this->availableSavedQueries->isNotEmpty())
        <div class="mt-6">
            <p class="mb-2 text-sm font-medium text-neutral-700">ブロックを追加:</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->availableBlocks as $block)
                    <button wire:click="addBlock('{{ $block->key() }}')"
                        class="rounded-full border border-neutral-300 px-3 py-1 text-xs text-neutral-600 hover:bg-neutral-50">
                        + {{ $block->label() }}
                    </button>
                @endforeach
                @foreach ($this->availableSavedQueries as $savedQuery)
                    <button wire:key="add-query-block-{{ $savedQuery->id }}"
                        wire:click="addBlock('{{ \App\Support\Dashboard\SavedIssueQueryBlock::keyFor($savedQuery) }}')"
                        class="rounded-full border border-brand-subtle px-3 py-1 text-xs text-brand-bold hover:bg-brand-subtlest">
                        + クエリ: {{ $savedQuery->name }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
