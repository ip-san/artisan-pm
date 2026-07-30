{{ $eventType === 'added' ? 'お知らせが投稿されました。' : 'お知らせにコメントが投稿されました。' }}({{ $actor->name }})

{{ $news->project->name }} - {{ $news->title }}
{{ $url }}
@if ($comment)

{{ $comment->content }}
@endif
@if ($footer)

--
{{ $footer }}
@endif
