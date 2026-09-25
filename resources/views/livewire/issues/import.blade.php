<?php

use App\Jobs\ImportIssuesJob;
use App\Models\Issue;
use App\Models\CustomField;
use App\Models\IssueCategory;
use App\Models\IssueImport;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\Version;
use App\Support\Import\CsvReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    /**
     * Target Issue fields a CSV column can be mapped to, and their labels.
     *
     * @var array<string, string>
     */
    public const IMPORTABLE_FIELDS = [
        'subject' => '題名(必須)',
        'description' => '説明',
        'tracker' => 'トラッカー(名前)',
        'status' => 'ステータス(名前)',
        'priority' => '優先度(名前)',
        'assigned_to' => '担当者(ログインID・氏名・メールアドレス)',
        'category' => 'カテゴリ(名前)',
        'fixed_version' => '対象バージョン(名前)',
        'parent' => '親課題(#番号)',
        'is_private' => '非公開フラグ',
        'start_date' => '開始日',
        'due_date' => '期日',
        'done_ratio' => '進捗率',
        'estimated_hours' => '予定工数',
        'unique_id' => '一意なID',
        'relation_relates' => '関連',
        'relation_blocks' => 'ブロックする',
        'relation_blocked' => 'ブロックされている',
        'relation_duplicates' => '重複する',
        'relation_duplicated' => '重複されている',
        'relation_precedes' => '先行',
        'relation_follows' => '後続',
        'relation_copied_to' => 'コピー先',
        'relation_copied_from' => 'コピー元',
    ];

    /**
     * Translated labels for IMPORTABLE_FIELDS (constants can't call __()).
     *
     * @return array<string, string>
     */
    public function importableFieldLabels(): array
    {
        return [
            'subject' => __('題名(必須)'),
            'description' => __('説明'),
            'tracker' => __('トラッカー(名前)'),
            'status' => __('ステータス(名前)'),
            'priority' => __('優先度(名前)'),
            'assigned_to' => __('担当者(ログインID・氏名・メールアドレス)'),
            'category' => __('カテゴリ(名前)'),
            'fixed_version' => __('対象バージョン(名前)'),
            'parent' => __('親課題(#番号)'),
            'is_private' => __('非公開フラグ'),
            'start_date' => __('開始日'),
            'due_date' => __('期日'),
            'done_ratio' => __('進捗率'),
            'estimated_hours' => __('予定工数'),
            'unique_id' => __('一意なID'),
            'relation_relates' => __('関連'),
            'relation_blocks' => __('ブロックする'),
            'relation_blocked' => __('ブロックされている'),
            'relation_duplicates' => __('重複する'),
            'relation_duplicated' => __('重複されている'),
            'relation_precedes' => __('先行'),
            'relation_follows' => __('後続'),
            'relation_copied_to' => __('コピー先'),
            'relation_copied_from' => __('コピー元'),
        ];
    }

    public Project $project;

    public $csvFile = null;

    /** @var array<int, string> */
    public array $headers = [];

    /** @var array<string, string> */
    public array $mapping = [];

    public bool $createCategories = false;

    public bool $createVersions = false;

    public function mount(Project $project): void
    {
        $this->authorize('import', [Issue::class, $project]);

        $this->project = $project;
    }

    #[Computed]
    public function canManageCategories(): bool
    {
        return auth()->user()?->can('create', [IssueCategory::class, $this->project]) ?? false;
    }

    #[Computed]
    public function canManageVersions(): bool
    {
        return auth()->user()?->can('create', [Version::class, $this->project]) ?? false;
    }

    /**
     * Custom fields a column can be mapped to (`cf_<id>` keys): those the
     * user may see and edit on an issue of one of the project's trackers —
     * Redmine's IssueImport#mappable_custom_fields. The import job checks
     * each row's tracker again.
     *
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function mappableCustomFields(): Collection
    {
        return $this->project->trackers
            ->flatMap(fn (Tracker $tracker) => (new Issue)
                ->forceFill(['project_id' => $this->project->id, 'tracker_id' => $tracker->id])
                ->setRelation('project', $this->project)
                ->relevantCustomFields()
                ->filter(fn (CustomField $field) => $field->editableBy(auth()->user())))
            ->unique('id')
            ->sortBy('position')
            ->values();
    }

    public function updatedCsvFile(): void
    {
        $this->validate(['csvFile' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $this->headers = CsvReader::header($this->csvFile->getRealPath());

        foreach (array_keys(self::IMPORTABLE_FIELDS) as $field) {
            $match = collect($this->headers)->first(
                fn (string $header) => Str::contains(Str::lower($header), $field)
            );

            $this->mapping[$field] = $match ?? '';
        }

        foreach ($this->mappableCustomFields as $customField) {
            $match = collect($this->headers)->first(
                fn (string $header) => mb_strtolower(trim($header)) === mb_strtolower($customField->name)
            );

            $this->mapping["cf_{$customField->id}"] = $match ?? '';
        }
    }

    public function startImport(): void
    {
        $this->authorize('import', [Issue::class, $this->project]);

        $this->validate([
            'csvFile' => ['required', 'file'],
            'mapping.subject' => ['required', 'string'],
        ]);

        $path = $this->csvFile->store('imports', 'local');

        $import = IssueImport::create([
            'project_id' => $this->project->id,
            'user_id' => auth()->id(),
            'original_filename' => $this->csvFile->getClientOriginalName(),
            'file_path' => $path,
            'column_mapping' => [
                ...array_filter($this->mapping),
                'create_categories' => $this->canManageCategories && $this->createCategories,
                'create_versions' => $this->canManageVersions && $this->createVersions,
            ],
        ]);

        ImportIssuesJob::dispatch($import);

        $this->redirect(route('issues.import-status', [$this->project, $import]), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">{{ $project->name }} — {{ __('CSVインポート') }}</h1>

    <form wire:submit="startImport" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('CSVファイル') }}</label>
            <input type="file" wire:model="csvFile" accept=".csv,text/csv" class="mt-1 block w-full text-sm text-neutral-700">
            @error('csvFile') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-neutral-500">{{ __('1行目はヘッダー行として扱われます。') }}</p>
            <p class="mt-1 text-xs text-neutral-500">{{ __('親課題と関連は「#番号」で既存の課題を指定します。「一意なID」の列を割り当てると、「#」の付かない値はファイル内の行の一意なIDを指します。先行/後続には「#12 3d」のように遅延日数を付けられます。関連は複数ならカンマで区切ります。') }}</p>
        </div>

        @if ($headers !== [])
            <div class="rounded-md border border-neutral-200 bg-surface p-4">
                <h2 class="text-sm font-semibold text-neutral-900 mb-3">{{ __('列のマッピング') }}</h2>
                <div class="space-y-3">
                    @foreach ($this->importableFieldLabels() as $field => $label)
                        <div class="grid grid-cols-2 items-center gap-3">
                            <label class="text-sm text-neutral-700">{{ $label }}</label>
                            <select wire:model="mapping.{{ $field }}" class="block w-full rounded-md border-neutral-300 text-sm">
                                <option value="">{{ __('(マッピングしない)') }}</option>
                                @foreach ($headers as $header)
                                    <option value="{{ $header }}">{{ $header }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                    @foreach ($this->mappableCustomFields as $customField)
                        <div class="grid grid-cols-2 items-center gap-3" wire:key="import-cf-{{ $customField->id }}">
                            <label class="text-sm text-neutral-700">{{ $customField->is_required ? $customField->name.__('(必須)') : $customField->name }}</label>
                            <select wire:model="mapping.cf_{{ $customField->id }}" class="block w-full rounded-md border-neutral-300 text-sm">
                                <option value="">{{ __('(マッピングしない)') }}</option>
                                @foreach ($headers as $header)
                                    <option value="{{ $header }}">{{ $header }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
                @error('mapping.subject') <p class="mt-2 text-sm text-danger-bolder">{{ $message }}</p> @enderror

                @if (($mapping['category'] ?? '') !== '' && $this->canManageCategories)
                    <label class="mt-3 flex items-center gap-2 text-sm text-neutral-700">
                        <input type="checkbox" wire:model="createCategories" class="rounded border-neutral-300">
                        {{ __('存在しないカテゴリ名は自動的に作成する') }}
                    </label>
                @endif

                @if (($mapping['fixed_version'] ?? '') !== '' && $this->canManageVersions)
                    <label class="mt-3 flex items-center gap-2 text-sm text-neutral-700">
                        <input type="checkbox" wire:model="createVersions" class="rounded border-neutral-300">
                        {{ __('存在しない対象バージョン名は自動的に作成する') }}
                    </label>
                @endif
            </div>

            <div class="flex gap-3">
                <button type="submit" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                    {{ __('インポート開始') }}
                </button>
                <a href="{{ route('issues.index', $project) }}" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                    {{ __('キャンセル') }}
                </a>
            </div>
        @endif
    </form>
</div>
