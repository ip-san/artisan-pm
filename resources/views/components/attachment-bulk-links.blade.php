{{--
    "Download all" (a ZIP) and "edit all" (names and descriptions) links for
    the attachments of one record — Redmine's object_attachments_download
    and object_attachments_edit. The ZIP link needs two or more files; the
    edit link is shown to whoever may update the record.
--}}
@props(['container', 'count'])

@php
    $alias = \App\Support\Attachments\AttachmentContainers::aliasFor($container);
@endphp

@if ($count > 1 || auth()->user()?->can('update', $container))
    <span {{ $attributes->merge(['class' => 'ml-3 text-xs font-normal']) }} data-attachment-bulk-links>
        @if ($count > 1)
            <a href="{{ route('attachments.download-all', [$alias, $container->getKey()]) }}" class="text-indigo-600 hover:underline">すべてダウンロード</a>
        @endif
        @can('update', $container)
            <a href="{{ route('attachments.edit-all', [$alias, $container->getKey()]) }}" class="ml-2 text-indigo-600 hover:underline">まとめて編集</a>
        @endcan
    </span>
@endif
