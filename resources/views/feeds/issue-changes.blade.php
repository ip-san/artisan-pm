<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <title>{{ $title }}</title>
    <link rel="self" href="{{ url()->full() }}" />
    <link rel="alternate" href="{{ $alternateUrl }}" />
    <id>{{ $alternateUrl }}</id>
    <updated>{{ ($journals->first()?->created_at ?? now())->toAtomString() }}</updated>
    <author>
        <name>{{ config('app.name') }}</name>
    </author>
    @foreach ($journals as $journal)
        @php($issue = $journal->issue)
        <entry>
            <title>{{ $issue->project->name }} - {{ $issue->tracker->name }} #{{ $issue->id }}: {{ $issue->subject }}</title>
            <link rel="alternate" href="{{ route('issues.show', [$issue->project, $issue]) }}" />
            <id>{{ route('issues.show', [$issue->project, $issue]) }}?journal_id={{ $journal->id }}</id>
            <updated>{{ $journal->created_at->toAtomString() }}</updated>
            <author>
                <name>{{ $journal->user->displayName() }}</name>
            </author>
            <content type="html">{{ '<ul>'.collect($details($journal))->map(fn ($line) => '<li>'.e($line).'</li>')->implode('').'</ul>'.($journal->notes ? '<p>'.nl2br(e($journal->notes)).'</p>' : '') }}</content>
        </entry>
    @endforeach
</feed>
