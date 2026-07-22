<?php

use App\Models\CustomField;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?Group $group = null;

    public string $name = '';

    public string $email = '';

    /** @var array<int|string, mixed> custom_field_id => raw input (or array for multi-value) */
    public array $customFieldValues = [];

    public function mount(?Group $group = null): void
    {
        if ($group?->exists) {
            $this->authorize('update', $group);

            $this->group = $group;
            $this->name = $group->name;

            $this->customFieldValues = $group->customFieldFormValues($group->relevantCustomFields());
        } else {
            $this->authorize('create', Group::class);
        }
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->group ? $this->group->users : collect();
    }

    /**
     * @return Collection<int, CustomField>
     */
    #[Computed]
    public function customFields(): Collection
    {
        return ($this->group ?? new Group)->relevantCustomFields();
    }

    public function save(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255', Rule::unique('groups', 'name')->ignore($this->group?->id)],
        ];

        $rules = [...$rules, ...CustomField::formValidationRules($this->customFields)];

        $data = $this->validate($rules);
        $customFieldData = $data['customFieldValues'] ?? [];
        unset($data['customFieldValues']);

        if ($this->group) {
            $this->group->update($data);
        } else {
            $this->group = Group::create($data);
        }

        $this->group->setCustomFieldValues($customFieldData);

        $this->redirect(route('groups.edit', $this->group), navigate: true);
    }

    public function addMember(): void
    {
        $this->authorize('update', $this->group);

        $data = $this->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $user = User::query()->where('email', $data['email'])->firstOrFail();

        $this->group->users()->syncWithoutDetaching($user);

        $this->reset('email');
        unset($this->members);
    }

    public function removeMember(int $userId): void
    {
        $this->authorize('update', $this->group);

        $this->group->users()->detach($userId);

        unset($this->members);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $group ? 'グループを編集' : '新規グループ' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
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
            <a href="{{ route('groups.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>

    @if ($group)
        <div class="mt-10">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">メンバー</h2>

            <form wire:submit="addMember" class="mb-4 flex items-end gap-3">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700">ユーザーのメールアドレス</label>
                    <input type="email" wire:model="email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    追加
                </button>
            </form>

            <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 bg-white">
                @forelse ($this->members as $member)
                    <li class="flex items-center justify-between px-4 py-3">
                        <span class="text-sm text-gray-900">{{ $member->name }} ({{ $member->email }})</span>
                        <button wire:click="removeMember({{ $member->id }})" wire:confirm="このメンバーをグループから削除しますか?"
                            class="text-sm text-red-600 hover:underline">
                            削除
                        </button>
                    </li>
                @empty
                    <li class="px-4 py-6 text-sm text-gray-500">メンバーがいません。</li>
                @endforelse
            </ul>
        </div>
    @endif
</div>
