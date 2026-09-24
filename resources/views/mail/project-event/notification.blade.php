<!doctype html>
<html>
<body style="font-family: sans-serif; font-size: 14px; color: #1f2933;">
    @if ($header)
        <div style="color: #6b7280; font-size: 12px;">{!! $headerHtml !!}</div>
        <hr>
    @endif

    <p>{{ $headline }}</p>

    <p><a href="{{ $url }}">{{ $title }}</a></p>

    @if ($body)
        <p style="white-space: pre-wrap;">{{ $body }}</p>
    @endif

    @if ($footer)
        <hr>
        <div style="color: #6b7280; font-size: 12px;">{!! $footerHtml !!}</div>
    @endif
</body>
</html>
