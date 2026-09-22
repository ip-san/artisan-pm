<?php

use App\Enums\WebhookEvent;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Webhook;
use App\Rules\PublicWebhookUrl;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public bool $editing = false;

    public ?int $editingId = null;

    public string $url = '';

    public string $secret = '';

    public ?int $project_id = null;

    /** @var array<string> */
    public array $events = [];

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    private function authorizeAccess(): void
    {
        abort_unless(
            Setting::get('webhooks_enabled', true)
                && app(AuthorizationService::class)->canGlobally(auth()->user(), 'use_webhooks'),
            403,
        );
    }

    /**
     * @return Collection<int, Webhook>
     */
    #[Computed]
    public function webhooks(): Collection
    {
        return Webhook::query()->with('project')->where('user_id', auth()->id())->orderBy('url')->get();
    }

    /**
     * Only projects where the user may use webhooks are offered — a hook
     * fires nowhere else (Redmine's Webhook.hooks_for).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        $authorization = app(AuthorizationService::class);

        return Project::query()->orderBy('name')->get()
            ->filter(fn (Project $project) => $authorization->can(auth()->user(), 'use_webhooks', $project))
            ->values();
    }

    public function startCreate(): void
    {
        $this->authorizeAccess();
        $this->resetForm();
        $this->editing = true;
    }

    public function startEdit(int $webhookId): void
    {
        $this->authorizeAccess();
        $webhook = $this->ownWebhook($webhookId);

        $this->editingId = $webhook->id;
        $this->url = $webhook->url;
        // The stored secret is never sent back to the browser; leaving the field blank keeps it.
        $this->secret = '';
        $this->project_id = $webhook->project_id;
        $this->events = $webhook->events ?? [];
        $this->is_active = $webhook->is_active;
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->authorizeAccess();

        $data = $this->validate([
            'url' => ['required', 'url', 'max:2048', new PublicWebhookUrl],
            'secret' => ['nullable', 'string'],
            'project_id' => ['nullable', Rule::in($this->projects->pluck('id')->all())],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::enum(WebhookEvent::class)],
            'is_active' => ['boolean'],
        ]);

        if ($data['secret'] === '') {
            unset($data['secret']);
        }

        if ($this->editingId !== null) {
            $this->ownWebhook($this->editingId)->update($data);
        } else {
            Webhook::create($data + ['name' => $data['url'], 'user_id' => auth()->id()]);
        }

        $this->resetForm();
        unset($this->webhooks);
    }

    public function delete(int $webhookId): void
    {
        $this->authorizeAccess();
        $this->ownWebhook($webhookId)->delete();

        unset($this->webhooks);
    }

    private function ownWebhook(int $webhookId): Webhook
    {
        return Webhook::query()->where('user_id', auth()->id())->findOrFail($webhookId);
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'editingId', 'url', 'secret', 'project_id', 'events', 'is_active');
        $this->resetValidation();
    }
}; ?>

<div class="max-w-2xl space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900">マイWebhook</h1>
        <button wire:click="startCreate" data-my-webhook-create
            class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
            新規Webhook
        </button>
    </div>

    <p class="text-sm text-neutral-600">
        自分が閲覧でき、かつ「Webhookの利用」権限を持つプロジェクトのイベントだけが送信されます。
    </p>

    @if ($editing)
        <form wire:submit="save" class="space-y-4 rounded-md border border-neutral-200 bg-white p-4" data-my-webhook-form>
            <div>
                <label class="block text-sm font-medium text-neutral-700">URL</label>
                <input type="text" wire:model="url" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('url') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-neutral-700">
                    シークレット{{ $editingId ? '(変更する場合のみ入力)' : '(任意)' }}
                </label>
                <input type="password" wire:model="secret" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-neutral-700">対象プロジェクト</label>
                <select wire:model="project_id" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    <option value="">Webhookを利用できるすべてのプロジェクト</option>
                    @foreach ($this->projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
                @error('project_id') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="mb-2 block text-sm font-medium text-neutral-700">イベント</span>
                <div class="flex flex-wrap gap-3">
                    @foreach (WebhookEvent::cases() as $event)
                        <label class="flex items-center gap-2 text-sm text-neutral-700">
                            <input type="checkbox" wire:model="events" value="{{ $event->value }}" class="rounded border-neutral-300">
                            {{ $event->value }}
                        </label>
                    @endforeach
                </div>
                @error('events') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="is_active" class="rounded border-neutral-300">
                有効にする
            </label>

            <div class="flex gap-3">
                <button type="submit" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">保存</button>
                <button type="button" wire:click="cancel" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">キャンセル</button>
            </div>
        </form>
    @endif

    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
        @forelse ($this->webhooks as $webhook)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <span class="font-medium text-neutral-900">{{ $webhook->url }}</span>
                    <span class="ml-2 text-xs text-neutral-500">{{ $webhook->project?->name ?? '全プロジェクト' }}</span>
                    @if (! $webhook->is_active)
                        <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">無効</span>
                    @endif
                </div>
                <div class="flex gap-3">
                    <button wire:click="startEdit({{ $webhook->id }})" class="text-sm text-brand-bold hover:underline">編集</button>
                    <button wire:click="delete({{ $webhook->id }})" wire:confirm="このWebhookを削除しますか?"
                        class="text-sm text-danger-bolder hover:underline">削除</button>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-neutral-500">Webhookがありません。</li>
        @endforelse
    </ul>
</div>
