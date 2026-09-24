<!doctype html>
<html>
<body style="font-family: sans-serif; font-size: 14px; color: #1f2933;">
    @if ($header)
        <div style="color: #6b7280; font-size: 12px;">{!! $headerHtml !!}</div>
        <hr>
    @endif

    <p>
        {{ $eventType === 'added' ? __('お知らせが投稿されました。') : __('お知らせにコメントが投稿されました。') }}
        ({{ $actor->displayName() }})
    </p>

    <p>
        <a href="{{ $url }}">{{ $news->project->name }} - {{ $news->title }}</a>
    </p>

    @if ($comment)
        <p style="white-space: pre-wrap;">{{ $comment->content }}</p>
    @endif

    @if ($footer)
        <hr>
        <div style="color: #6b7280; font-size: 12px;">{!! $footerHtml !!}</div>
    @endif
</body>
</html>
