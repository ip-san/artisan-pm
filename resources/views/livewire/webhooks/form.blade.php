<?php

use App\Enums\WebhookEvent;
use App\Models\Project;
use App\Models\Webhook;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?Webhook $webhook = null;

    public string $name = '';

    public string $url = '';

    public string $secret = '';

    public ?int $project_id = null;

    /** @var array<string> */
    public array $events = [];

    public bool $is_active = true;

    public function mount(?Webhook $webhook = null): void
    {
        if ($webhook?->exists) {
            $this->authorize('update', $webhook);

            $this->webhook = $webhook;
            $this->name = $webhook->name;
            $this->url = $webhook->url;
            // secret is intentionally left blank — the stored value is
            // never round-tripped back into the form; submitting with
            // this field blank keeps the existing secret unchanged.
            $this->project_id = $webhook->project_id;
            $this->events = $webhook->events ?? [];
            $this->is_active = $webhook->is_active;
        } else {
            $this->authorize('create', Webhook::class);
        }
    }

    #[Computed]
    public function projects(): Collection
    {
        return Project::query()->orderBy('name')->get();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'secret' => ['nullable', 'string'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::enum(WebhookEvent::class)],
            'is_active' => ['boolean'],
        ]);

        if ($data['secret'] === '') {
            unset($data['secret']);
        }

        if ($this->webhook) {
            $this->webhook->update($data);
        } else {
            Webhook::create($data);
        }

        $this->redirect(route('webhooks.index'), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $webhook ? 'Webhookを編集' : '新規Webhook' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">URL</label>
            <input type="text" wire:model="url" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('url') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">
                シークレット{{ $webhook ? '(変更する場合のみ入力)' : '(任意)' }}
            </label>
            <input type="password" wire:model="secret" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            <p class="mt-1 text-xs text-gray-500">設定すると、送信するリクエストに署名が付与されます。</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">対象プロジェクト</label>
            <select wire:model="project_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                <option value="">全プロジェクト</option>
                @foreach ($this->projects as $project)
                    <option value="{{ $project->id }}">{{ $project->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <span class="block text-sm font-medium text-gray-700 mb-2">イベント</span>
            <div class="flex flex-wrap gap-3">
                @foreach (WebhookEvent::cases() as $event)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="events" value="{{ $event->value }}" class="rounded border-gray-300">
                        {{ $event->value }}
                    </label>
                @endforeach
            </div>
            @error('events') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model="is_active" class="rounded border-gray-300">
            有効にする
        </label>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('webhooks.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
