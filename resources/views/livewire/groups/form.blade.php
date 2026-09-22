<?php

use App\Models\CustomField;
use App\Concerns\ManagesPrincipalMemberships;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    use ManagesPrincipalMemberships;

    public ?Group $group = null;

    public string $name = '';

    public bool $twofaRequired = false;

    public string $userSearch = '';

    public ?int $selectedUserId = null;

    public bool $showUserDropdown = false;

    /** @var array<int|string, mixed> custom_field_id => raw input (or array for multi-value) */
    public array $customFieldValues = [];

    public function mount(?Group $group = null): void
    {
        if ($group?->exists) {
            $this->authorize('update', $group);

            $this->group = $group;
            $this->name = $group->name;
            $this->twofaRequired = $group->twofa_required;

            $this->customFieldValues = $group->customFieldFormValues($group->relevantCustomFields());
        } else {
            $this->authorize('create', Group::class);
        }
    }

    protected function membershipPrincipal(): Group
    {
        return $this->group ?? abort(404);
    }

    protected function membershipColumn(): string
    {
        return 'group_id';
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

    /**
     * The per-group switch only has an effect while the site-wide setting
     * is "optional" or "required for administrators" — Redmine disables the
     * checkbox otherwise (groups/_form.html.erb).
     */
    #[Computed]
    public function twofaGroupSwitchEnabled(): bool
    {
        return in_array(Setting::get('twofa', '0'), ['1', '3'], true);
    }

    public function save(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255', Rule::unique('groups', 'name')->ignore($this->group?->id)],
        ];

        $rules = [...$rules, ...CustomField::formValidationRules($this->customFields)];

        $data = $this->validate($rules);
        $customFieldData = CustomField::filterEditableValues($this->customFields, $data['customFieldValues'] ?? [], auth()->user());
        unset($data['customFieldValues']);

        // A disabled checkbox never submits, so leave the stored value alone
        // while the site-wide setting makes the switch meaningless.
        if ($this->twofaGroupSwitchEnabled) {
            $data['twofa_required'] = $this->twofaRequired;
        }

        if ($this->group) {
            $this->group->update($data);
        } else {
            $this->group = Group::create($data);
        }

        $this->group->setCustomFieldValues($customFieldData);

        $this->redirect(route('groups.edit', $this->group), navigate: true);
    }

    public function updatedUserSearch(): void
    {
        $this->selectedUserId = null;
        $this->showUserDropdown = trim($this->userSearch) !== '';
    }

    public function selectUser(int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        $this->selectedUserId = $user->id;
        $this->userSearch = "{$user->name} ({$user->email})";
        $this->showUserDropdown = false;
    }

    /**
     * Name/email substring matches, excluding users already in this
     * group — matches Redmine's autocomplete_for_user (used by the group
     * members form) which likewise excludes existing members.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function userCandidates(): Collection
    {
        $search = trim($this->userSearch);

        if ($search === '' || $this->selectedUserId !== null) {
            return collect();
        }

        $existingUserIds = $this->group->users()->pluck('users.id');

        return User::query()
            ->whereNotIn('id', $existingUserIds)
            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    public function addMember(): void
    {
        $this->authorize('update', $this->group);

        $data = $this->validate([
            'selectedUserId' => ['required', 'exists:users,id'],
        ]);

        $this->group->users()->syncWithoutDetaching($data['selectedUserId']);

        $this->reset('userSearch', 'selectedUserId');
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
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">
        {{ $group ? 'グループを編集' : '新規グループ' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-neutral-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="flex items-center gap-2 text-sm text-neutral-700">
                <input type="checkbox" wire:model="twofaRequired" class="rounded border-neutral-300"
                    @disabled(! $this->twofaGroupSwitchEnabled)>
                このグループのメンバーに二要素認証を必須にする
            </label>
            @if (\App\Models\Setting::get('twofa', '0') === '2')
                <p class="mt-1 text-xs text-neutral-500">二要素認証は全ユーザーに必須のため、この設定は不要です。</p>
            @elseif (! $this->twofaGroupSwitchEnabled)
                <p class="mt-1 text-xs text-neutral-500">サイト設定の二要素認証が「任意」または「管理者のみ必須」のときに使えます。</p>
            @endif
        </div>

        @if ($this->customFields->isNotEmpty())
            <div class="space-y-4 border-t border-neutral-200 pt-4">
                @foreach ($this->customFields as $field)
                    <x-custom-field-input :field="$field" wire-model="customFieldValues" :required="$field->is_required" :disabled="! $field->editableBy(auth()->user())" />
                @endforeach
            </div>
        @endif

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                保存
            </button>
            <a href="{{ route('groups.index') }}" class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                キャンセル
            </a>
        </div>
    </form>

    @if ($group)
        <div class="mt-10">
            <h2 class="text-sm font-semibold text-neutral-900 mb-3">メンバー</h2>

            <form wire:submit="addMember" class="mb-4 flex items-end gap-3">
                <div class="relative flex-1">
                    <label class="block text-sm font-medium text-neutral-700">ユーザー</label>
                    <input type="text" wire:model.live.debounce.300ms="userSearch"
                        placeholder="名前またはメールアドレスで検索" autocomplete="off"
                        class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @if ($showUserDropdown)
                        <ul class="absolute z-10 mt-1 w-full rounded-md border border-neutral-200 bg-white shadow-lg">
                            @forelse ($this->userCandidates as $candidate)
                                <li wire:key="user-candidate-{{ $candidate->id }}">
                                    <button type="button" wire:click="selectUser({{ $candidate->id }})"
                                        class="block w-full px-3 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50">
                                        {{ $candidate->name }} ({{ $candidate->email }})
                                    </button>
                                </li>
                            @empty
                                <li class="px-3 py-2 text-sm text-neutral-500">該当するユーザーがいません。</li>
                            @endforelse
                        </ul>
                    @endif
                    @error('selectedUserId') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded-md bg-brand-bold px-3 py-2 text-sm font-medium text-white hover:bg-brand">
                    追加
                </button>
            </form>

            <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-white">
                @forelse ($this->members as $member)
                    <li class="flex items-center justify-between px-4 py-3">
                        <span class="text-sm text-neutral-900">{{ $member->name }} ({{ $member->email }})</span>
                        <button wire:click="removeMember({{ $member->id }})" wire:confirm="このメンバーをグループから削除しますか?"
                            class="text-sm text-danger-bolder hover:underline">
                            削除
                        </button>
                    </li>
                @empty
                    <li class="px-4 py-6 text-sm text-neutral-500">メンバーがいません。</li>
                @endforelse
            </ul>
        </div>
    @endif

    @if ($group)
        <x-principal-memberships :memberships="$this->principalMemberships" :projects="$this->membershipProjects" :roles="$this->membershipRoles"
            :editing="$editingMembershipId ? $this->principalMemberships->firstWhere('id', $editingMembershipId) : null" />
    @endif
</div>
