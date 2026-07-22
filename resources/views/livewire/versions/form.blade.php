<?php

use App\Enums\VersionSharing;
use App\Enums\VersionStatus;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Version;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public ?Version $version = null;

    public string $name = '';

    public string $description = '';

    public string $status = 'open';

    public string $sharing = 'none';

    public ?string $due_date = null;

    public string $wiki_page_title = '';

    /** @var array<int|string, mixed> custom_field_id => raw input (or array for multi-value) */
    public array $customFieldValues = [];

    public function mount(Project $project, ?Version $version = null): void
    {
        $this->project = $project;

        if ($version?->exists) {
            abort_unless($version->project_id === $project->id, 404);

            $this->authorize('update', $version);

            $this->version = $version;
            $this->name = $version->name;
            $this->description = (string) $version->description;
            $this->status = $version->status->value;
            $this->sharing = $version->sharing->value;
            $this->due_date = $version->due_date?->toDateString();
            $this->wiki_page_title = (string) $version->wiki_page_title;

            foreach ($version->relevantCustomFields() as $field) {
                $this->customFieldValues[$field->id] = $field->multiple
                    ? $version->customFieldValues->where('custom_field_id', $field->id)->map(fn ($v) => $v->value())->all()
                    : $version->customValue($field);
            }
        } else {
            $this->authorize('create', [Version::class, $project]);
        }
    }

    #[Computed]
    public function wikiPages(): Collection
    {
        return $this->project->wikiPages()->orderBy('title')->get();
    }

    /**
     * Sharing levels the current user may pick — resolved against the
     * version being edited, or a transient version bound to this project
     * for the new-version case so allowedSharings() can still consult the
     * project's root.
     *
     * @return array<int, VersionSharing>
     */
    #[Computed]
    public function allowedSharings(): array
    {
        $version = $this->version ?? (new Version)->setRelation('project', $this->project);

        return $version->allowedSharings(auth()->user());
    }

    /**
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function customFields(): Collection
    {
        return ($this->version ?? (new Version)->setRelation('project', $this->project))
            ->relevantCustomFields();
    }

    public function save(): void
    {
        $rules = [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('versions', 'name')->where('project_id', $this->project->id)->ignore($this->version?->id),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(array_map(fn (VersionStatus $s) => $s->value, VersionStatus::cases()))],
            'sharing' => ['required', Rule::in(array_map(fn (VersionSharing $s) => $s->value, $this->allowedSharings))],
            'due_date' => ['nullable', 'date'],
            'wiki_page_title' => ['nullable', 'string', Rule::in([...$this->wikiPages->pluck('title')->all(), ''])],
        ];

        foreach ($this->customFields as $field) {
            $key = "customFieldValues.{$field->id}";
            $presence = $field->is_required ? 'required' : 'nullable';

            if ($field->multiple) {
                $rules[$key] = [$presence, 'array'];
                $rules["{$key}.*"] = $field->format()->validationRules($field);
            } else {
                $rules[$key] = [$presence, ...$field->format()->validationRules($field)];
            }
        }

        $data = $this->validate($rules);
        $customFieldData = $data['customFieldValues'] ?? [];
        unset($data['customFieldValues']);

        $data['wiki_page_title'] = $data['wiki_page_title'] !== '' ? $data['wiki_page_title'] : null;
        $data['project_id'] = $this->project->id;

        if ($this->version) {
            $this->version->update($data);
        } else {
            $this->version = Version::create($data);
        }

        $this->version->setCustomFieldValues($customFieldData);

        $this->redirect(route('versions.index', $this->project), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $version ? 'バージョンを編集' : '新規バージョン' }}
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
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">ステータス</label>
            <select wire:model="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="open">オープン</option>
                <option value="locked">ロック中</option>
                <option value="closed">クローズ</option>
            </select>
            @error('status') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">共有</label>
            <select wire:model="sharing" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @foreach ($this->allowedSharings as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500">このバージョンを他のプロジェクトの課題にも割り当て可能にする範囲を指定します。</p>
            @error('sharing') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">期日</label>
            <input type="date" wire:model="due_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('due_date') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">関連Wikiページ</label>
            <select wire:model="wiki_page_title" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="">なし</option>
                @foreach ($this->wikiPages as $page)
                    <option value="{{ $page->title }}">{{ $page->title }}</option>
                @endforeach
            </select>
            @error('wiki_page_title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        @if ($this->customFields->isNotEmpty())
            <div class="space-y-4 border-t border-gray-200 pt-4">
                @foreach ($this->customFields as $field)
                    <x-custom-field-input :field="$field" wire-model="customFieldValues" :required="$field->is_required" />
                @endforeach
            </div>
        @endif

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('versions.index', $project) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
