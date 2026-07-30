<!doctype html>
<html>
<body style="font-family: sans-serif; font-size: 14px; color: #1f2933;">
    <p>
        {{ $eventType === 'added' ? 'お知らせが投稿されました。' : 'お知らせにコメントが投稿されました。' }}
        ({{ $actor->name }})
    </p>

    <p>
        <a href="{{ $url }}">{{ $news->project->name }} - {{ $news->title }}</a>
    </p>

    @if ($comment)
        <p style="white-space: pre-wrap;">{{ $comment->content }}</p>
    @endif

    @if ($footer)
        <hr>
        <p style="color: #6b7280; font-size: 12px;">{{ $footer }}</p>
    @endif
</body>
</html>
