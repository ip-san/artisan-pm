<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>{{ $project->name }}</title>
    <style>
        <x-pdf.cjk-font />
        @page { margin: 20px 24px; }
        body { font-size: 10px; color: #111827; }
        h1 { font-size: 13px; margin: 0 0 8px; }
        .page-section { page-break-before: always; }
        .page-title { font-size: 11px; font-weight: bold; margin: 0 0 4px; }
        .prose { font-size: 10px; line-height: 1.5; }
        .prose p { margin: 0 0 6px; }
    </style>
</head>
<body>
    <h1>{{ $project->name }}</h1>

    @foreach ($entries as $entry)
        {{-- Not a CSS :first-child rule — dompdf didn't reliably honor one
             paired with page-break-before, confirmed empirically by
             rasterizing (it left a near-blank title-only first page even
             with :first-child { page-break-before: avoid } present). --}}
        <div class="{{ $loop->first ? '' : 'page-section' }}">
            <div class="page-title">{{ str_repeat('— ', $entry['depth']) }}{{ $entry['page']->title }}</div>
            <div class="prose">{!! $entry['html'] !!}</div>
        </div>
    @endforeach
</body>
</html>
