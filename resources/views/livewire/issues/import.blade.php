<?php

use App\Jobs\ImportIssuesJob;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueImport;
use App\Models\Project;
use App\Models\Version;
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
        'assigned_to' => '担当者(メールアドレス)',
        'category' => 'カテゴリ(名前)',
        'fixed_version' => '対象バージョン(名前)',
        'parent' => '親課題(#番号)',
        'is_private' => '非公開フラグ',
        'start_date' => '開始日',
        'due_date' => '期日',
        'done_ratio' => '進捗率',
    ];

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
        $this->authorize('create', [Issue::class, $project]);

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

    public function updatedCsvFile(): void
    {
        $this->validate(['csvFile' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $handle = fopen($this->csvFile->getRealPath(), 'r');
        $this->headers = $handle ? (fgetcsv($handle) ?: []) : [];

        if ($handle) {
            fclose($handle);
        }

        foreach (array_keys(self::IMPORTABLE_FIELDS) as $field) {
            $match = collect($this->headers)->first(
                fn (string $header) => Str::contains(Str::lower($header), $field)
            );

            $this->mapping[$field] = $match ?? '';
        }
    }

    public function startImport(): void
    {
        $this->authorize('create', [Issue::class, $this->project]);

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
    <h1 class="text-xl font-semibold text-gray-900 mb-6">{{ $project->name }} — CSVインポート</h1>

    <form wire:submit="startImport" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">CSVファイル</label>
            <input type="file" wire:model="csvFile" accept=".csv,text/csv" class="mt-1 block w-full text-sm text-gray-700">
            @error('csvFile') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-gray-500">1行目はヘッダー行として扱われます。</p>
        </div>

        @if ($headers !== [])
            <div class="rounded-md border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold text-gray-900 mb-3">列のマッピング</h2>
                <div class="space-y-3">
                    @foreach (self::IMPORTABLE_FIELDS as $field => $label)
                        <div class="grid grid-cols-2 items-center gap-3">
                            <label class="text-sm text-gray-700">{{ $label }}</label>
                            <select wire:model="mapping.{{ $field }}" class="block w-full rounded-md border-gray-300 text-sm">
                                <option value="">(マッピングしない)</option>
                                @foreach ($headers as $header)
                                    <option value="{{ $header }}">{{ $header }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
                @error('mapping.subject') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror

                @if (($mapping['category'] ?? '') !== '' && $this->canManageCategories)
                    <label class="mt-3 flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="createCategories" class="rounded border-gray-300">
                        存在しないカテゴリ名は自動的に作成する
                    </label>
                @endif

                @if (($mapping['fixed_version'] ?? '') !== '' && $this->canManageVersions)
                    <label class="mt-3 flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="createVersions" class="rounded border-gray-300">
                        存在しない対象バージョン名は自動的に作成する
                    </label>
                @endif
            </div>

            <div class="flex gap-3">
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    インポート開始
                </button>
                <a href="{{ route('issues.index', $project) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    キャンセル
                </a>
            </div>
        @endif
    </form>
</div>
