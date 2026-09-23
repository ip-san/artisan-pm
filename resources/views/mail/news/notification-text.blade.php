@if ($header)
{{ $header }}

--

@endif
{{ $eventType === 'added' ? __('お知らせが投稿されました。') : __('お知らせにコメントが投稿されました。') }}({{ $actor->name }})

{{ $news->project->name }} - {{ $news->title }}
{{ $url }}
@if ($comment)

{{ $comment->content }}
@endif
@if ($footer)

--
{{ $footer }}
@endif
