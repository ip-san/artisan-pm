<x-layouts.app>
    <div class="max-w-4xl">
        <div class="mb-4 flex items-start justify-between gap-4">
            <div>
                <h1 class="break-all text-xl font-semibold text-neutral-900">{{ $media->file_name }}</h1>
                <p class="mt-1 text-sm text-neutral-500">
                    {{ $media->human_readable_size }}
                    @if ($media->getCustomProperty('description'))
                        — {{ $media->getCustomProperty('description') }}
                    @endif
                </p>
            </div>
            <a href="{{ route('attachments.show', $media) }}" class="shrink-0 rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">ダウンロード</a>
        </div>

        @if ($total > 1 && $position !== null)
            <p class="mb-3 flex items-center gap-3 text-sm text-neutral-600" data-attachment-pager>
                @if ($previous)
                    <a href="{{ route('attachments.preview', $previous) }}" class="text-brand-bold hover:underline">« 前へ</a>
                @endif
                <span>{{ $position }} / {{ $total }}</span>
                @if ($next)
                    <a href="{{ route('attachments.preview', $next) }}" class="text-brand-bold hover:underline">次へ »</a>
                @endif
            </p>
        @endif

        @if ($kind === 'image')
            <img src="{{ route('attachments.inline', $media) }}" alt="{{ $media->file_name }}" class="max-w-full rounded-md border border-neutral-200">
        @elseif ($kind === 'pdf')
            <iframe src="{{ route('attachments.inline', $media) }}" title="{{ $media->file_name }}" class="h-[80vh] w-full rounded-md border border-neutral-200"></iframe>
        @elseif ($kind === 'diff' && $text !== null)
            <pre class="overflow-x-auto rounded-md border border-neutral-200 bg-neutral-900 p-4 text-xs text-neutral-100" data-attachment-diff>@foreach (preg_split('/\R/', $text) as $line)<span class="{{ str_starts_with($line, '+') && ! str_starts_with($line, '+++') ? 'text-success' : (str_starts_with($line, '-') && ! str_starts_with($line, '---') ? 'text-danger-subtle' : (str_starts_with($line, '@@') ? 'text-cyan-400' : '')) }}">{{ $line }}</span>
@endforeach</pre>
        @elseif ($kind === 'text' && $text !== null)
            <pre class="overflow-x-auto rounded-md border border-neutral-200 bg-neutral-50 p-4 text-xs text-neutral-800" data-attachment-text>{{ $text }}</pre>
        @else
            <p class="text-sm text-neutral-500">このファイルはブラウザ上に表示できません。ダウンロードしてください。</p>
        @endif
    </div>
</x-layouts.app>
