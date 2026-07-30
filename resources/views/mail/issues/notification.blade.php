<!doctype html>
<html>
<body style="font-family: sans-serif; font-size: 14px; color: #1f2933;">
    <p>
        {{ $eventType === 'created' ? '課題が作成されました。' : '課題が更新されました。' }}
        ({{ $actor->name }})
    </p>

    <p>
        <a href="{{ $url }}">{{ $issue->project->name }} - {{ $issue->tracker->name }} #{{ $issue->id }}: {{ $issue->subject }}</a>
    </p>

    @if (! empty($changes))
        <table cellpadding="4" cellspacing="0" style="border-collapse: collapse;">
            @foreach ($changes as $change)
                <tr>
                    <td style="color: #6b7280;">{{ $change['label'] }}</td>
                    <td>{{ $change['old'] ?? '(未設定)' }} → {{ $change['new'] ?? '(未設定)' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($journal?->notes)
        <p style="white-space: pre-wrap;">{{ $journal->notes }}</p>
    @endif

    @if ($footer)
        <hr>
        <p style="color: #6b7280; font-size: 12px;">{{ $footer }}</p>
    @endif
</body>
</html>
