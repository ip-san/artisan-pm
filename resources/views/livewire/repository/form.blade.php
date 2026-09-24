<?php

use App\Enums\RepositoryType;
use App\Models\Project;
use App\Models\Repository;
use App\Support\Scm\CodesetConverter;
use App\Models\Setting;
use App\Rules\RemoteRepositoryUrl;
use App\Rules\WithinRepositoriesRoot;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public ?Repository $repository = null;

    public bool $isNew = false;

    public string $type = 'git';

    public string $path = '';

    /**
     * A10-01b: a remote Subversion URL, used instead of $path. The stored
     * password is never loaded into $password (it would be sent to the
     * browser in the component snapshot) — a blank password on save keeps
     * the stored one.
     */
    public string $url = '';

    public string $login = '';

    public string $password = '';

    public ?string $identifier = null;

    public ?string $log_encoding = null;

    public ?string $path_encoding = null;

    /**
     * Every literal segment any repository.* route registers right after
     * "/repository/" (routes/web.php) — an identifier equal to one of
     * these would make its own .repo URLs (e.g.
     * `route('repository.index.repo', ['repositoryParam' => 'edit'])` →
     * `/repository/edit`) collide with that route's identifier-less
     * sibling, since the identifier-less routes are registered first and
     * win. Differs from Redmine's own reserved-identifier list because
     * this app's route segment names differ from Redmine's controller
     * actions.
     *
     * @var array<int, string>
     */
    private const RESERVED_IDENTIFIERS = [
        'new', 'edit', 'committers', 'stats', 'compare', 'revisions',
        'browse', 'entry', 'annotate', 'history', 'raw',
    ];

    /**
     * $isNew comes from a route default (routes/web.php's
     * `repository.create` registration), not from inspecting the matched
     * route name — the latter can't be exercised by Livewire::test(),
     * which calls mount() directly and never runs an actual route match
     * (the exact gap a real HTTP test caught elsewhere in this slice).
     * A route default is bound by parameter name like any other route
     * parameter, so it's simulatable in a component test too.
     */
    public function mount(Project $project, ?string $repositoryParam = null, bool $isNew = false): void
    {
        $this->authorize('manage', [Repository::class, $project]);

        $this->project = $project;
        $this->isNew = $isNew;
        $this->repository = $this->isNew ? null : $project->resolveRepository($repositoryParam);

        if ($this->repository) {
            $this->type = $this->repository->type->value;
            $this->path = (string) $this->repository->path;
            $this->url = (string) $this->repository->url;
            $this->login = (string) $this->repository->login;
            $this->identifier = $this->repository->identifier;
            $this->log_encoding = $this->repository->log_encoding;
            $this->path_encoding = $this->repository->path_encoding;
        }
    }

    /**
     * Matches Redmine's Repository#identifier_frozen? (repository.rb): once
     * an identifier is set it can never change (Repository's own `saving`
     * hook silently ignores any attempted write), so the field only makes
     * sense to expose while it's still blank — a new repository, or an
     * existing one nobody has ever given an identifier.
     */
    public function identifierEditable(): bool
    {
        return $this->repository === null || $this->repository->identifier === null || $this->repository->identifier === '';
    }

    /**
     * Types selectable in the form — restricted to the site-wide
     * enabled_scm_types setting, except an existing repository's own
     * current type always stays selectable even if since disabled, so
     * editing its path doesn't get blocked by an unrelated later change.
     *
     * @return Collection<int, RepositoryType>
     */
    #[Computed]
    public function enabledTypes(): Collection
    {
        $enabled = Setting::get('enabled_scm_types', array_map(fn (RepositoryType $type) => $type->value, RepositoryType::cases()));

        return collect(RepositoryType::cases())
            ->filter(fn (RepositoryType $case) => in_array($case->value, $enabled, true) || $case === $this->repository?->type)
            ->values();
    }

    /**
     * A10-01b: whether this user may set a remote URL and credentials
     * (manage_remote_repositories), and whether the server allows remote
     * repositories at all (config('scm.allowed_hosts')). The fields are also
     * shown for an already-remote repository, so its URL stays visible.
     */
    #[Computed]
    public function canManageRemote(): bool
    {
        return auth()->user()?->can('manageRemote', [Repository::class, $this->project]) ?? false;
    }

    public function showsRemoteFields(): bool
    {
        return ($this->canManageRemote && config('scm.allowed_hosts', []) !== []) || $this->repository?->isRemote();
    }

    public function save(): void
    {
        $wantsRemote = trim($this->url) !== '';
        $isRemoteNow = (bool) $this->repository?->isRemote();

        // Setting or changing a remote URL needs manage_remote_repositories;
        // without it an existing remote repository's location is left
        // untouched (only its identifier/encodings can be edited).
        if ($wantsRemote && trim($this->url) !== (string) $this->repository?->url) {
            $this->authorize('manageRemote', [Repository::class, $this->project]);
        }

        $locationLocked = $isRemoteNow && ! $this->canManageRemote;

        $rules = [
            'type' => ['required', Rule::in($this->enabledTypes->pluck('value')->all())],
        ];

        if ($locationLocked) {
            // Nothing: path/url/login/password are neither validated nor written.
        } elseif ($wantsRemote) {
            // Same bail discipline as the path rules below: the final
            // closure makes svn open a network connection, which must
            // never happen for a URL RemoteRepositoryUrl rejected.
            $rules['url'] = [
                'required', 'bail', 'string', 'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->type !== RepositoryType::Svn->value) {
                        $fail(__('リモートURLはSubversionリポジトリでのみ指定できます。'));
                    } elseif (trim($this->path) !== '') {
                        $fail(__('パスとURLはどちらか一方だけ指定してください。'));
                    }
                },
                new RemoteRepositoryUrl,
                function (string $attribute, mixed $value, Closure $fail): void {
                    $candidate = new Repository([
                        'type' => $this->type,
                        'url' => trim($value),
                        'login' => $this->login !== '' ? $this->login : null,
                        'password' => $this->effectivePassword(),
                    ]);

                    if (! $candidate->adapter()->isAvailable()) {
                        $fail(__('リポジトリにアクセスできません。URL・ログインID・パスワードを確認してください。'));
                    }
                },
            ];
            $rules['login'] = ['nullable', 'string', 'max:60'];
            $rules['password'] = ['nullable', 'string', 'max:255'];
        } else {
            // bail is load-bearing here, not just an optimization: the
            // closure below shells out via the adapter, and it must never
            // run against a path WithinRepositoriesRoot has already
            // rejected — that containment check is what makes it safe to
            // invoke git/svn against this path at all.
            $rules['path'] = [
                'required', 'bail', 'string', 'max:500',
                new WithinRepositoriesRoot,
                function (string $attribute, mixed $value, Closure $fail): void {
                    $candidate = new Repository(['type' => $this->type, 'path' => $value]);

                    if (! $candidate->adapter()->isAvailable()) {
                        $fail(__('有効な:typeリポジトリではありません。', ['type' => $this->type]));
                    }
                },
            ];
        }

        $encodingRule = function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && ! CodesetConverter::isKnownEncoding((string) $value)) {
                $fail(__('「:value」は未対応のエンコーディングです。', ['value' => $value]));
            }
        };
        $rules['log_encoding'] = ['nullable', 'string', 'max:50', $encodingRule];
        $rules['path_encoding'] = ['nullable', 'string', 'max:50', $encodingRule];

        // Only validated (and therefore only ever written) while still
        // editable — once frozen, the field isn't rendered at all, so
        // there's nothing meaningful to validate on submit.
        if ($this->identifierEditable()) {
            $rules['identifier'] = [
                'nullable', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    if (preg_match('/^\d+$/', $value) === 1) {
                        $fail(__('識別子は数字のみにはできません。'));
                    }

                    if (in_array($value, self::RESERVED_IDENTIFIERS, true)) {
                        $fail(__('この識別子は予約語のため使用できません。'));
                    }
                },
                Rule::unique('repositories', 'identifier')->where('project_id', $this->project->id)->ignore($this->repository?->id),
            ];
        }

        $data = $this->validate($rules);

        if (! $locationLocked) {
            if ($wantsRemote) {
                $data['url'] = trim($data['url']);
                $data['path'] = null;
                $data['login'] = filled($data['login'] ?? null) ? $data['login'] : null;
                // Blank keeps the stored password; clearing the login drops it.
                $data['password'] = $data['login'] === null ? null : $this->effectivePassword();
            } else {
                $data['url'] = null;
                $data['login'] = null;
                $data['password'] = null;
            }
        }

        // A Livewire text input submits '' for an untouched/cleared field,
        // not null — the composite (project_id, identifier) unique index
        // treats '' as a real value distinct from NULL, so a second
        // identifier-less repository in the same project would collide
        // with the first unless this is normalized before saving.
        $data['log_encoding'] = filled($data['log_encoding'] ?? null) ? trim($data['log_encoding']) : null;
        $data['path_encoding'] = filled($data['path_encoding'] ?? null) ? trim($data['path_encoding']) : null;

        if (array_key_exists('identifier', $data)) {
            $data['identifier'] = $data['identifier'] === '' ? null : $data['identifier'];
        }

        $this->password = '';

        if ($this->repository) {
            $this->repository->update($data);
            $this->redirect(route($this->repository->routeName('repository.index'), $this->repository->routeParameters()), navigate: true);
        } else {
            $data['project_id'] = $this->project->id;
            $created = Repository::create($data);
            $this->redirect(route($created->routeName('repository.index'), $created->routeParameters()), navigate: true);
        }
    }

    /**
     * The password to use: the one typed, or — left blank while editing
     * a remote repository without changing its URL — the stored one. A
     * changed URL never inherits it, so a stored password can't be
     * redirected to a different (even allow-listed) server.
     */
    private function effectivePassword(): ?string
    {
        if ($this->password !== '') {
            return $this->password;
        }

        return $this->repository?->isRemote() && trim($this->url) === $this->repository->url
            ? $this->repository->password
            : null;
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-neutral-900 mb-6">{{ $isNew ? __('リポジトリの追加') : __('リポジトリ設定') }}</h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('種別') }}</label>
            <select wire:model="type" class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @foreach ($this->enabledTypes as $case)
                    <option value="{{ $case->value }}">{{ $case->value }}</option>
                @endforeach
            </select>
            @error('type') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('パス') }}</label>
            <input type="text" wire:model="path" placeholder="/path/to/repo.git"
                class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
            @error('path') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
        </div>

        @if ($this->showsRemoteFields())
            <fieldset class="space-y-3 rounded-md border border-neutral-200 p-4" @disabled(! $this->canManageRemote)>
                <legend class="px-1 text-sm font-medium text-neutral-700">{{ __('リモートリポジトリ(Subversionのみ)') }}</legend>
                <p class="text-xs text-neutral-500">
                    {{ __('パスの代わりに、許可されたホストの svn://、http://、https:// URL を指定できます。') }}
                </p>
                <div>
                    <label for="repository-url" class="block text-sm font-medium text-neutral-700">{{ __('URL') }}</label>
                    <input id="repository-url" type="text" wire:model="url" placeholder="https://svn.example.com/repos/project/trunk"
                        class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                    @error('url') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="repository-login" class="block text-sm font-medium text-neutral-700">{{ __('ログインID') }}</label>
                        <input id="repository-login" type="text" wire:model="login" autocomplete="off"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                        @error('login') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="repository-password" class="block text-sm font-medium text-neutral-700">{{ __('パスワード') }}</label>
                        <input id="repository-password" type="password" wire:model="password" autocomplete="new-password"
                            placeholder="{{ $this->repository?->password !== null ? __('変更しない場合は空欄') : '' }}"
                            class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                        @error('password') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>
        @endif

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-neutral-700">{{ __('コミットログのエンコーディング') }}</label>
                <input type="text" wire:model="log_encoding" placeholder="{{ __('空欄で全体設定を使用(例: SJIS-win)') }}"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('log_encoding') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-700">{{ __('パス名のエンコーディング') }}</label>
                <input type="text" wire:model="path_encoding" placeholder="{{ __('空欄でUTF-8/全体設定') }}"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('path_encoding') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
            </div>
            <p class="mt-1 text-xs text-neutral-500">
                {{ __('管理者が配置したリポジトリ用ディレクトリ(:root)配下のパスのみ指定できます。', ['root' => config('scm.repositories_root')]) }}
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium text-neutral-700">{{ __('識別子') }}</label>
            @if ($this->identifierEditable())
                <input type="text" wire:model="identifier" placeholder="main"
                    class="mt-1 block w-full rounded-md border-neutral-300 shadow-sm sm:text-sm">
                @error('identifier') <p class="mt-1 text-sm text-danger-bolder">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('プロジェクト内で複数のリポジトリを区別するためのURL用の名前です(半角英小文字・数字・ハイフン・アンダースコアのみ、数字のみは不可)。空欄のままにもできますが、一度設定すると変更できません。') }}
                </p>
            @else
                <p class="mt-1 text-sm text-neutral-700">{{ $identifier }}</p>
                <p class="mt-1 text-xs text-neutral-500">{{ __('識別子は一度設定すると変更できません。') }}</p>
            @endif
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-brand-bold px-4 py-2 text-sm font-medium text-white hover:bg-brand">
                {{ __('保存') }}
            </button>
            <a href="{{ $this->repository ? route($this->repository->routeName('repository.index'), $this->repository->routeParameters()) : route('repository.index', $project) }}"
                class="rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
                {{ __('キャンセル') }}
            </a>
        </div>
    </form>
</div>
