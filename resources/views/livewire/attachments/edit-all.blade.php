<?php

use App\Support\Attachments\AttachmentContainers;
use App\Support\Attachments\AttachmentRenamer;
use App\Support\Attachments\AttachmentValidationRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Redmine's attachments#edit_all: rename and describe every attachment of
 * one record on a single page. Allowed to whoever may update the record.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    public string $type = '';

    public int $containerId = 0;

    /** @var array<int, string> media id => file name */
    public array $names = [];

    /** @var array<int, string> media id => description */
    public array $descriptions = [];

    public function mount(string $type, int $id): void
    {
        $container = AttachmentContainers::find($type, $id);

        abort_if($container === null, 404);

        $this->authorize('update', $container);

        $this->type = $type;
        $this->containerId = $id;

        foreach ($container->getMedia('attachments') as $media) {
            $this->names[$media->id] = $media->file_name;
            $this->descriptions[$media->id] = (string) $media->getCustomProperty('description', '');
        }
    }

    /**
     * @return Model&HasMedia
     */
    private function container(): Model
    {
        return AttachmentContainers::find($this->type, $this->containerId) ?? abort(404);
    }

    /**
     * @return array<int, string>
     */
    private function relationsForUrl(): array
    {
        return match ($this->type) {
            'message' => ['board.project'],
            default => ['project'],
        };
    }

    /**
     * @return Collection<int, Media>
     */
    private function attachments(): Collection
    {
        return $this->container()->getMedia('attachments');
    }

    public function save(): void
    {
        $container = $this->container();

        $this->authorize('update', $container);

        $attachments = $container->getMedia('attachments')->keyBy('id');

        $this->validate([
            'names.*' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! AttachmentRenamer::isValidName((string) $value)) {
                    $fail('ファイル名が不正です。');
                } elseif (! AttachmentValidationRules::isExtensionAllowed(pathinfo((string) $value, PATHINFO_EXTENSION))) {
                    $fail('このファイル形式は許可されていません。');
                }
            }],
            'descriptions.*' => ['nullable', 'string', 'max:255'],
        ]);

        // Only attachments that belong to this record can be touched, whatever
        // ids the client sent.
        foreach ($this->names as $mediaId => $name) {
            $media = $attachments->get((int) $mediaId);

            if ($media === null) {
                continue;
            }

            AttachmentRenamer::rename($media, trim($name));

            $description = trim((string) ($this->descriptions[$mediaId] ?? ''));
            $description === ''
                ? $media->forgetCustomProperty('description')
                : $media->setCustomProperty('description', $description);
            $media->save();
        }

        session()->flash('status', '添付ファイルを更新しました。');

        $this->redirect(AttachmentContainers::url($container->loadMissing($this->relationsForUrl())), navigate: true);
    }
}; ?>

<div class="max-w-3xl">
    <h1 class="mb-6 text-xl font-semibold text-gray-900">添付ファイルの編集</h1>

    <form wire:submit="save" class="space-y-4">
        @foreach ($this->attachments() as $media)
            <div class="rounded-md border border-gray-200 bg-white p-3" wire:key="edit-attachment-{{ $media->id }}">
                <label class="block text-xs font-medium text-gray-700">ファイル名</label>
                <input type="text" wire:model="names.{{ $media->id }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                @error("names.{$media->id}") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

                <label class="mt-2 block text-xs font-medium text-gray-700">説明</label>
                <input type="text" wire:model="descriptions.{{ $media->id }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                @error("descriptions.{$media->id}") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endforeach

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">保存</button>
            <a href="{{ \App\Support\Attachments\AttachmentContainers::url($this->container()->loadMissing($this->relationsForUrl())) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">キャンセル</a>
        </div>
    </form>
</div>
