@if ($header)
{{ $header }}

--

@endif
{{ $headline }}

{{ $title }}
{{ $url }}
@if ($body)

{{ $body }}
@endif
@if ($footer)

--
{{ $footer }}
@endif
