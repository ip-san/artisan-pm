<?php

use App\Jobs\ImportUsersJob;
use App\Models\CustomField;
use App\Models\User;
use App\Models\UserImport;
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
     * The user fields a CSV column can be mapped to — Redmine's
     * UserImport::AUTO_MAPPABLE_FIELDS, with this app's single name field.
     *
     * @var array<string, string>
     */
    public const IMPORTABLE_FIELDS = [
        'login' => 'ログインID(必須)',
        'name' => '名前(必須)',
        'email' => 'メールアドレス(必須)',
        'password' => 'パスワード(認証方式が無い場合は必須)',
        'language' => '言語',
        'admin' => '管理者(1/yes/はい)',
        'must_change_passwd' => '次回ログイン時にパスワード変更を要求(1/yes/はい)',
        'auth_source' => '認証方式(名前)',
        'status' => 'ステータス(有効/承認待ち/ロック中)',
    ];

    public $csvFile = null;

    /** @var array<int, string> */
    public array $headers = [];

    /** @var array<string, string> */
    public array $mapping = [];

    public function mount(): void
    {
        $this->authorize('create', User::class);
    }

    /**
     * The user custom fields, mappable as cf_{id}.
     *
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function customFields(): Collection
    {
        return CustomField::query()->where('customized_type', User::customizableType())->orderBy('position')->get();
    }

    public function updatedCsvFile(): void
    {
        $this->validate(['csvFile' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        [$this->headers] = ImportUsersJob::readCsv($this->csvFile->getRealPath());

        $targets = self::IMPORTABLE_FIELDS + $this->customFields->mapWithKeys(fn (CustomField $field) => ["cf_{$field->id}" => $field->name])->all();

        foreach ($targets as $field => $label) {
            $match = collect($this->headers)->first(
                fn (string $header) => Str::lower(trim($header)) === Str::lower(str_starts_with($field, 'cf_') ? $label : $field)
            );

            $this->mapping[$field] = $match ?? '';
        }
    }

    public function startImport(): void
    {
        $this->authorize('create', User::class);

        $this->validate([
            'csvFile' => ['required', 'file'],
            'mapping.login' => ['required', 'string'],
            'mapping.name' => ['required', 'string'],
            'mapping.email' => ['required', 'string'],
        ]);

        $import = UserImport::create([
            'user_id' => auth()->id(),
            'original_filename' => $this->csvFile->getClientOriginalName(),
            'file_path' => $this->csvFile->store('imports', 'local'),
            'column_mapping' => array_filter($this->mapping),
        ]);

        ImportUsersJob::dispatch($import);

        $this->redirect(route('users.import-status', $import), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">ユーザーCSVインポート</h1>

    <form wire:submit="startImport" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">CSVファイル</label>
            <input type="file" wire:model="csvFile" accept=".csv,text/csv" class="mt-1 block w-full text-sm text-gray-700">
            @error('csvFile') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-gray-500">1行目はヘッダー行として扱われます。文字コードはUTF-8です。</p>
        </div>

        @if ($headers !== [])
            <div class="rounded-md border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold text-gray-900 mb-3">列のマッピング</h2>
                <div class="space-y-3">
                    @foreach (self::IMPORTABLE_FIELDS + $this->customFields->mapWithKeys(fn ($field) => ["cf_{$field->id}" => $field->name])->all() as $field => $label)
                        <div class="grid grid-cols-2 items-center gap-3" wire:key="user-import-map-{{ $field }}">
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
                @error('mapping.login') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                @error('mapping.name') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                @error('mapping.email') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-3">
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">インポート開始</button>
                <a href="{{ route('users.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">キャンセル</a>
            </div>
        @endif
    </form>
</div>
