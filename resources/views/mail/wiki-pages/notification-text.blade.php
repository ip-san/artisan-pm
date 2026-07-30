{{ $eventType === 'created' ? 'Wikiページが追加されました。' : 'Wikiページが更新されました。' }}({{ $actor->name }})

{{ $wikiPage->project->name }} - {{ $wikiPage->title }}
{{ $url }}
@if ($footer)

--
{{ $footer }}
@endif
