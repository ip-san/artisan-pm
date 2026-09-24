<!doctype html>
<html>
<body style="font-family: sans-serif; font-size: 14px; color: #1f2933;">
    @if ($header)
        <div style="color: #6b7280; font-size: 12px;">{!! $headerHtml !!}</div>
        <hr>
    @endif

    <p>
        {{ $eventType === 'created' ? __('Wikiページが追加されました。') : __('Wikiページが更新されました。') }}
        ({{ $actor->displayName() }})
    </p>

    <p>
        <a href="{{ $url }}">{{ $wikiPage->project->name }} - {{ $wikiPage->title }}</a>
    </p>

    @if ($footer)
        <hr>
        <div style="color: #6b7280; font-size: 12px;">{!! $footerHtml !!}</div>
    @endif
</body>
</html>
