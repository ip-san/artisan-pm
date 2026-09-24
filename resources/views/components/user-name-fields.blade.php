{{-- The name inputs of the admin user form and the account page: Redmine's lastname/firstname beside this app's single name (docs/design/gap-A4-10b.md). --}}
<div class="space-y-4" data-user-name-fields>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('姓') }}</label>
            <input type="text" wire:model="lastname" maxlength="255" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
            @error('lastname') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('名') }}</label>
            <input type="text" wire:model="firstname" maxlength="30" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
            @error('firstname') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium text-neutral-700">{{ __('名前') }}</label>
        <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
        <p class="mt-1 text-xs text-neutral-500">{{ __('姓と名を入力した場合、名前は「名 姓」に置き換えられます。') }}</p>
        @error('name') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
    </div>
</div>
