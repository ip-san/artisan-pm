<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>{{ $wikiPage->title }}</title>
    <style>
        <x-pdf.cjk-font />
        @page { margin: 20px 24px; }
        body { font-size: 10px; color: #111827; }
        h1 { font-size: 13px; margin: 0 0 2px; }
        .meta { color: #6b7280; font-size: 9px; margin-bottom: 10px; }
        .prose { font-size: 10px; line-height: 1.5; }
        .prose p { margin: 0 0 6px; }
    </style>
</head>
<body>
    <h1>{{ $wikiPage->title }}</h1>
    <div class="meta">
        {{ $wikiPage->currentVersion?->created_at?->format('Y-m-d H:i') }}
        @if ($wikiPage->currentVersion?->author)
            - {{ $wikiPage->currentVersion->author->name }}
        @endif
    </div>

    <div class="prose">{!! $contentHtml !!}</div>
</body>
</html>
