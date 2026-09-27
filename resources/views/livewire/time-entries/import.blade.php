<?php

use App\Jobs\ImportTimeEntriesJob;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimeEntryImport;
use App\Support\Import\CsvReader;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;

    /**
     * Target TimeEntry fields a CSV column can be mapped to, and their
     * labels — mirrors Redmine's TimeEntryImport::AUTO_MAPPABLE_FIELDS
     * (activity/user/issue_id/spent_on/hours/comments).
     *
     * @var array<string, string>
     */
    public const IMPORTABLE_FIELDS = [
        'spent_on' => '日付(必須)',
        'hours' => '時間(必須)',
        'activity' => '作業分類(名前)',
        'issue' => '課題(#番号)',
        'user' => 'ユーザー(メールアドレス、log_time_for_other_users権限が必要)',
        'comments' => 'コメント',
    ];

    /**
     * Translated labels for IMPORTABLE_FIELDS (constants can't call __()).
     *
     * @return array<string, string>
     */
    public function importableFieldLabels(): array
    {
        return [
            'spent_on' => __('日付(必須)'),
            'hours' => __('時間(必須)'),
            'activity' => __('作業分類(名前)'),
            'issue' => __('課題(#番号)'),
            'user' => __('ユーザー(メールアドレス、log_time_for_other_users権限が必要)'),
            'comments' => __('コメント'),
        ];
    }

    public Project $project;

    public $csvFile = null;

    /** @var array<int, string> */
    public array $headers = [];

    /** @var array<string, string> */
    public array $mapping = [];

    public function mount(Project $project): void
    {
        $this->authorize('import', [TimeEntry::class, $project]);

        $this->project = $project;
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
    }

    public function startImport(): void
    {
        $this->authorize('import', [TimeEntry::class, $this->project]);

        $this->validate([
            'csvFile' => ['required', 'file'],
            'mapping.hours' => ['required', 'string'],
            'mapping.spent_on' => ['required', 'string'],
        ]);

        $path = $this->csvFile->store('imports', 'local');

        $import = TimeEntryImport::create([
            'project_id' => $this->project->id,
            'user_id' => auth()->id(),
            'original_filename' => $this->csvFile->getClientOriginalName(),
            'file_path' => $path,
            'column_mapping' => array_filter($this->mapping),
        ]);

        ImportTimeEntriesJob::dispatch($import);

        $this->redirect(route('time-entries.import-status', [$this->project, $import]), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">{{ $project->name }} — {{ __('工数CSVインポート') }}</h1>

    <form wire:submit="startImport" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('CSVファイル') }}</label>
            <input type="file" wire:model="csvFile" accept=".csv,text/csv" class="mt-1 block w-full text-sm text-neutral-700">
            @error('csvFile') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-neutral-500">{{ __('1行目はヘッダー行として扱われます。') }}</p>
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
                </div>
                @error('mapping.hours') <p class="mt-2 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                @error('mapping.spent_on') <p class="mt-2 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">
                    {{ __('インポート開始') }}
                </button>
                <a href="{{ route('time-entries.index', $project) }}" class="btn btn-secondary">
                    {{ __('キャンセル') }}
                </a>
            </div>
        @endif
    </form>
</div>
