<?php

use App\Models\Project;
use App\Models\Repository;
use App\Support\Scm\ScmBlameLine;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Repository $repository;

    public string $path;

    public function mount(Project $project, string $path): void
    {
        $this->authorize('browse', [Repository::class, $project]);

        $repository = $project->repository;
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
}; ?>

<div>
    <div class="mb-6">
        <p class="text-sm text-gray-500">
            <a href="{{ route('repository.index', $project) }}" class="text-indigo-600 hover:underline">リポジトリ</a>
            /
            <a href="{{ route('repository.entry', [$project, $this->path]) }}" class="text-indigo-600 hover:underline">
                {{ $this->path }}
            </a>
        </p>
        <h1 class="text-xl font-semibold text-gray-900 font-mono">注釈: {{ $this->path }}</h1>
    </div>

    @if ($this->lines === [])
        <p class="text-sm text-gray-500">注釈を表示できません(バイナリファイル、または空のファイルの可能性があります)。</p>
    @else
        <div class="overflow-x-auto rounded-md border border-gray-200">
            <table class="w-full text-xs">
                <tbody>
                    @foreach ($this->lines as $index => $line)
                        <tr wire:key="blame-line-{{ $index }}" class="border-b border-gray-100 last:border-b-0">
                            <td class="whitespace-nowrap px-2 py-0.5 text-right text-gray-400 select-none">{{ $index + 1 }}</td>
                            <td class="whitespace-nowrap px-2 py-0.5 font-mono text-gray-500">{{ substr($line->revision, 0, 8) }}</td>
                            <td class="whitespace-nowrap px-2 py-0.5 text-gray-600">{{ $line->author }}</td>
                            <td class="px-2 py-0.5 font-mono text-gray-900"><pre class="whitespace-pre-wrap">{{ $line->content }}</pre></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
