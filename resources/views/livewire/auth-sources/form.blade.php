<?php

use App\Exceptions\LdapConnectionTestException;
use App\Models\AuthSource;
use App\Support\Ldap\LdapAuthenticator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public ?AuthSource $authSource = null;

    public string $name = '';

    public string $host = '';

    public int $port = 389;

    public bool $use_tls = false;

    public string $base_dn = '';

    public string $account = '';

    public string $account_password = '';

    public string $attr_login = 'uid';

    public string $attr_name = 'cn';

    public string $attr_mail = 'mail';

    public bool $onthefly_register = false;

    public int $timeout = 5;

    public ?bool $connectionTestPassed = null;

    public string $connectionTestMessage = '';

    public function mount(?AuthSource $authSource = null): void
    {
        if ($authSource?->exists) {
            $this->authorize('update', $authSource);

            $this->authSource = $authSource;
            $this->name = $authSource->name;
            $this->host = $authSource->host;
            $this->port = $authSource->port;
            $this->use_tls = $authSource->use_tls;
            $this->base_dn = $authSource->base_dn;
            $this->account = (string) $authSource->account;
            // account_password is intentionally left blank — the stored
            // value is never round-tripped back into the form; submitting
            // with this field blank keeps the existing password unchanged.
            $this->attr_login = $authSource->attr_login;
            $this->attr_name = $authSource->attr_name;
            $this->attr_mail = $authSource->attr_mail;
            $this->onthefly_register = $authSource->onthefly_register;
            $this->timeout = $authSource->timeout;
        } else {
            $this->authorize('create', AuthSource::class);
        }
    }

    /**
     * Tests the persisted record — matches Redmine's own "Test" link,
     * which likewise operates on the saved AuthSource rather than
     * whatever's currently typed into the form. Unsaved edits need to be
     * saved first to be reflected in the test.
     */
    public function testConnection(): void
    {
        $this->authorize('update', $this->authSource);

        $source = AuthSource::findOrFail($this->authSource->id);

        try {
            app(LdapAuthenticator::class)->testConnection($source);

            $this->connectionTestPassed = true;
            $this->connectionTestMessage = '接続に成功しました。';
        } catch (LdapConnectionTestException $e) {
            $this->connectionTestPassed = false;
            $this->connectionTestMessage = "接続できませんでした: {$e->getMessage()}";
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('auth_sources', 'name')->ignore($this->authSource?->id)],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'use_tls' => ['boolean'],
            'base_dn' => ['required', 'string', 'max:255'],
            'account' => ['nullable', 'string', 'max:255'],
            'account_password' => ['nullable', 'string'],
            'attr_login' => ['required', 'string', 'max:255'],
            'attr_name' => ['required', 'string', 'max:255'],
            'attr_mail' => ['required', 'string', 'max:255'],
            'onthefly_register' => ['boolean'],
            'timeout' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        $data['account'] = $data['account'] !== '' ? $data['account'] : null;

        if ($data['account_password'] === '') {
            unset($data['account_password']);
        }

        if ($this->authSource) {
            $this->authSource->update($data);
        } else {
            AuthSource::create($data);
        }

        $this->redirect(route('auth-sources.index'), navigate: true);
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">
        {{ $authSource ? '認証ソースを編集' : '新規認証ソース' }}
    </h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">名前</label>
            <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div class="col-span-2">
                <label class="block text-sm font-medium text-gray-700">ホスト</label>
                <input type="text" wire:model="host" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('host') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">ポート</label>
                <input type="number" wire:model="port" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('port') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model="use_tls" class="rounded border-gray-300">
            TLSを使用する
        </label>

        <div>
            <label class="block text-sm font-medium text-gray-700">ベースDN</label>
            <input type="text" wire:model="base_dn" placeholder="dc=example,dc=com" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('base_dn') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
            <p class="mb-2 text-xs text-gray-600">
                アカウントを空欄にすると、ログインIDから直接DNを組み立ててバインドします(direct bind)。
                指定すると、このアカウントで検索した上でユーザーのDNとして再バインドします(search+bind)。
            </p>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">検索用アカウントDN(任意)</label>
                    <input type="text" wire:model="account" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">
                        検索用アカウントのパスワード{{ $authSource ? '(変更する場合のみ入力)' : '' }}
                    </label>
                    <input type="password" wire:model="account_password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                </div>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">ログイン属性</label>
                <input type="text" wire:model="attr_login" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('attr_login') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">氏名属性</label>
                <input type="text" wire:model="attr_name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('attr_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">メール属性</label>
                <input type="text" wire:model="attr_mail" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('attr_mail') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">タイムアウト(秒)</label>
            <input type="number" wire:model="timeout" class="mt-1 block w-32 rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('timeout') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" wire:model="onthefly_register" class="rounded border-gray-300">
            未登録ユーザーの自動登録を許可する(初回ログイン時にアカウントを自動作成)
        </label>

        @if ($authSource)
            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                <button type="button" wire:click="testConnection"
                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    接続をテスト
                </button>
                <p class="mt-1 text-xs text-gray-500">保存済みの設定でテストします。未保存の変更はまず保存してください。</p>
                @if ($connectionTestPassed !== null)
                    <p class="mt-2 text-sm {{ $connectionTestPassed ? 'text-green-700' : 'text-red-600' }}">
                        {{ $connectionTestMessage }}
                    </p>
                @endif
            </div>
        @endif

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ route('auth-sources.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
