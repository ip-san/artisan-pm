<?php

use App\Enums\RepositoryType;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Setting;
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

    public ?string $identifier = null;

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
            $this->path = $this->repository->path;
            $this->identifier = $this->repository->identifier;
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

    public function save(): void
    {
        $rules = [
            'type' => ['required', Rule::in($this->enabledTypes->pluck('value')->all())],
            // bail is load-bearing here, not just an optimization: the
            // closure below shells out via the adapter, and it must never
            // run against a path WithinRepositoriesRoot has already
            // rejected — that containment check is what makes it safe to
            // invoke git/svn against this path at all.
            'path' => [
                'required', 'bail', 'string', 'max:500',
                new WithinRepositoriesRoot,
                function (string $attribute, mixed $value, Closure $fail): void {
                    $candidate = new Repository(['type' => $this->type, 'path' => $value]);

                    if (! $candidate->adapter()->isAvailable()) {
                        $fail("有効な{$this->type}リポジトリではありません。");
                    }
                },
            ],
        ];

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
                        $fail('識別子は数字のみにはできません。');
                    }

                    if (in_array($value, self::RESERVED_IDENTIFIERS, true)) {
                        $fail('この識別子は予約語のため使用できません。');
                    }
                },
                Rule::unique('repositories', 'identifier')->where('project_id', $this->project->id)->ignore($this->repository?->id),
            ];
        }

        $data = $this->validate($rules);

        // A Livewire text input submits '' for an untouched/cleared field,
        // not null — the composite (project_id, identifier) unique index
        // treats '' as a real value distinct from NULL, so a second
        // identifier-less repository in the same project would collide
        // with the first unless this is normalized before saving.
        if (array_key_exists('identifier', $data)) {
            $data['identifier'] = $data['identifier'] === '' ? null : $data['identifier'];
        }

        if ($this->repository) {
            $this->repository->update($data);
            $this->redirect(route($this->repository->routeName('repository.index'), $this->repository->routeParameters()), navigate: true);
        } else {
            $data['project_id'] = $this->project->id;
            $created = Repository::create($data);
            $this->redirect(route($created->routeName('repository.index'), $created->routeParameters()), navigate: true);
        }
    }
}; ?>

<div class="max-w-xl">
    <h1 class="text-xl font-semibold text-gray-900 mb-6">{{ $isNew ? 'リポジトリの追加' : 'リポジトリ設定' }}</h1>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">種別</label>
            <select wire:model="type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @foreach ($this->enabledTypes as $case)
                    <option value="{{ $case->value }}">{{ $case->value }}</option>
                @endforeach
            </select>
            @error('type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">パス</label>
            <input type="text" wire:model="path" placeholder="/path/to/repo.git"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
            @error('path') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-gray-500">
                管理者が配置したリポジトリ用ディレクトリ({{ config('scm.repositories_root') }})配下のパスのみ指定できます。
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">識別子</label>
            @if ($this->identifierEditable())
                <input type="text" wire:model="identifier" placeholder="main"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                @error('identifier') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">
                    プロジェクト内で複数のリポジトリを区別するためのURL用の名前です(半角英小文字・数字・ハイフン・アンダースコアのみ、数字のみは不可)。空欄のままにもできますが、一度設定すると変更できません。
                </p>
            @else
                <p class="mt-1 text-sm text-gray-700">{{ $identifier }}</p>
                <p class="mt-1 text-xs text-gray-500">識別子は一度設定すると変更できません。</p>
            @endif
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                保存
            </button>
            <a href="{{ $this->repository ? route($this->repository->routeName('repository.index'), $this->repository->routeParameters()) : route('repository.index', $project) }}"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                キャンセル
            </a>
        </div>
    </form>
</div>
