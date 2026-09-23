@if ($header)
{{ $header }}

--

@endif
{{ $eventType === 'created' ? __('Wikiページが追加されました。') : __('Wikiページが更新されました。') }}({{ $actor->name }})

{{ $wikiPage->project->name }} - {{ $wikiPage->title }}
{{ $url }}
@if ($footer)

--
{{ $footer }}
@endif
