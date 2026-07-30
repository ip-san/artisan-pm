<!doctype html>
<html>
<body style="font-family: sans-serif; font-size: 14px; color: #1f2933;">
    <p>
        {{ $eventType === 'created' ? 'Wikiページが追加されました。' : 'Wikiページが更新されました。' }}
        ({{ $actor->name }})
    </p>

    <p>
        <a href="{{ $url }}">{{ $wikiPage->project->name }} - {{ $wikiPage->title }}</a>
    </p>

    @if ($footer)
        <hr>
        <p style="color: #6b7280; font-size: 12px;">{{ $footer }}</p>
    @endif
</body>
</html>
