<!doctype html>
<html>
<body style="font-family: sans-serif; font-size: 14px; color: #1f2933;">
    @if ($header)
        <p style="color: #6b7280; font-size: 12px;">{{ $header }}</p>
        <hr>
    @endif

    <p>{{ $headline }}</p>

    <p><a href="{{ $url }}">{{ $title }}</a></p>

    @if ($body)
        <p style="white-space: pre-wrap;">{{ $body }}</p>
    @endif

    @if ($footer)
        <hr>
        <p style="color: #6b7280; font-size: 12px;">{{ $footer }}</p>
    @endif
</body>
</html>
