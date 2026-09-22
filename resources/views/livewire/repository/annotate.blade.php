<?php

use App\Models\Project;
use App\Models\Repository;
use App\Support\Scm\BlameBlocks;
use App\Support\Scm\ScmBlameLine;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Repository $repository;

    public string $path;

    public function mount(Project $project, string $path, ?string $repositoryParam = null): void
    {
        $this->authorize('browse', [Repository::class, $project]);

        $repository = $project->resolveRepository($repositoryParam);
        abort_if($repository === null, 404);

        $this->project = $project;
        $this->repository = $repository;
        $this->path = trim($path, '/');
    }

    /**
     * @return array<int, ScmBlameLine>
     */
    #[Computed]
    public function lines(): array
    {
        return $this->repository->adapter()->blame('HEAD', $this->path);
    }

    /**
     * Per-line block layout: revision/author only on a block's first line,
     * one of 12 background colours per revision, and a top border where a
     * block starts after a different one.
     *
     * @return array<int, array{showMeta: bool, colorIndex: int, isChange: bool}>
     */
    #[Computed]
    public function blocks(): array
    {
        return BlameBlocks::annotate($this->lines);
    }

    /**
     * Literal class names (not built from the index) so Tailwind sees them.
     *
     * @var array<int, string>
     */
    public const BLOCK_COLORS = [
        'bg-red-50', 'bg-orange-50', 'bg-amber-50', 'bg-lime-50', 'bg-green-50', 'bg-teal-50',
        'bg-cyan-50', 'bg-sky-50', 'bg-indigo-50', 'bg-violet-50', 'bg-fuchsia-50', 'bg-rose-50',
    ];
}; ?>

<div>
    <div class="mb-6">
        <p class="text-sm text-neutral-500">
            <a href="{{ route($repository->routeName('repository.index'), $repository->routeParameters()) }}" class="text-brand-bold hover:underline">リポジトリ</a>
            /
            <a href="{{ route($repository->routeName('repository.entry'), $repository->routeParameters(['path' => $this->path])) }}" class="text-brand-bold hover:underline">
                {{ $this->path }}
            </a>
        </p>
        <h1 class="text-xl font-semibold text-neutral-900 font-mono">注釈: {{ $this->path }}</h1>
    </div>

    @if ($this->lines === [])
        <p class="text-sm text-neutral-500">注釈を表示できません(バイナリファイル、または空のファイルの可能性があります)。</p>
    @else
        <div class="overflow-x-auto rounded-md border border-neutral-200">
            <table class="w-full text-xs">
                <tbody>
                    @foreach ($this->lines as $index => $line)
                        @php $block = $this->blocks[$index]; @endphp
                        <tr id="L{{ $index + 1 }}" wire:key="blame-line-{{ $index }}"
                            class="{{ $this::BLOCK_COLORS[$block['colorIndex']] }} {{ $block['isChange'] ? 'border-t border-neutral-300' : '' }}">
                            <td class="whitespace-nowrap px-2 py-0.5 text-right text-neutral-400 select-none">{{ $index + 1 }}</td>
                            <td class="whitespace-nowrap px-2 py-0.5 font-mono text-neutral-500">{{ $block['showMeta'] ? substr($line->revision, 0, 8) : '' }}</td>
                            <td class="whitespace-nowrap px-2 py-0.5 text-neutral-600">{{ $block['showMeta'] ? $line->author : '' }}</td>
                            <td class="px-2 py-0.5 font-mono text-neutral-900"><pre class="whitespace-pre-wrap">{{ $line->content }}</pre></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
