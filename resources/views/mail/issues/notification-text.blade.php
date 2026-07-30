{{ $eventType === 'created' ? '課題が作成されました。' : '課題が更新されました。' }}({{ $actor->name }})

{{ $issue->project->name }} - {{ $issue->tracker->name }} #{{ $issue->id }}: {{ $issue->subject }}
{{ $url }}
@if (! empty($changes))

@foreach ($changes as $change)
* {{ $change['label'] }}: {{ $change['old'] ?? '(未設定)' }} → {{ $change['new'] ?? '(未設定)' }}
@endforeach
@endif
@if ($journal?->notes)

{{ $journal->notes }}
@endif
@if ($footer)

--
{{ $footer }}
@endif
