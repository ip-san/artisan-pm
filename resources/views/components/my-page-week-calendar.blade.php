{{--
    Redmine's calendar My Page block: a single-week grid (start/due date
    markers, same ▶/◀/◆ scheme as the full calendar page) rather than a
    flat list — see App\Support\Dashboard\Blocks\CalendarBlock::weekDays().
--}}
@props(['days'])
<div class="overflow-x-auto">
    <table class="w-full table-fixed text-xs">
        <tbody>
            <tr class="divide-x divide-neutral-100">
                @foreach ($days as $day)
                    <td wire:key="my-page-cal-{{ $day['date']->toDateString() }}" class="h-24 align-top px-1 py-1">
                        <div class="{{ $day['date']->toDateString() === \App\Support\Format\DateTimes::today()->toDateString() ? 'font-bold text-brand-bold' : 'text-neutral-500' }}">
                            {{ $day['date']->day }}
                        </div>
                        <ul class="mt-1 space-y-0.5">
                            @foreach ($day['entries'] as $entry)
                                @php $issue = $entry['issue']; @endphp
                                <li class="truncate" wire:key="my-page-cal-{{ $day['date']->toDateString() }}-{{ $issue->id }}-{{ $entry['marker'] }}">
                                    @php [$markerLabel, $markerSymbol] = match ($entry['marker']) { 'start' => [__('開始日'), '▶'], 'due' => [__('期日'), '◀'], default => [__('開始日=期日'), '◆'] }; @endphp
                                    <span class="text-neutral-400" title="{{ $markerLabel }}">{{ $markerSymbol }}</span>
                                    <a href="{{ route('issues.show', [$issue->project, $issue]) }}" class="text-brand-bold hover:underline"
                                        title="{{ $issue->tracker->name }} #{{ $issue->id }}: {{ $issue->subject }}">
                                        #{{ $issue->id }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </td>
                @endforeach
            </tr>
        </tbody>
    </table>
</div>
