<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <title>{{ $title }}</title>
    <link rel="self" href="{{ url()->current() }}" />
    <link rel="alternate" href="{{ $alternateUrl }}" />
    <id>{{ $alternateUrl }}</id>
    <updated>{{ ($entries->first()?->occurredAt ?? now())->toAtomString() }}</updated>
    <author>
        <name>{{ config('app.name') }}</name>
    </author>
    @foreach ($entries as $entry)
        <entry>
            <title>{{ $entry->title }}</title>
            <link rel="alternate" href="{{ $entry->url }}" />
            <id>{{ $entry->url }}</id>
            <updated>{{ $entry->occurredAt->toAtomString() }}</updated>
            @if ($entry->authorName)
                <author>
                    <name>{{ $entry->authorName }}</name>
                </author>
            @endif
            <content type="text">{{ $entry->title }}</content>
        </entry>
    @endforeach
</feed>
