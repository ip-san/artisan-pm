{{--
    The Gantt chart shared by the project and cross-project pages: a label
    column and a timeline with Redmine's zoom levels — months always, ISO
    week numbers from zoom 2, days from zoom 3 (with the weekday at zoom 4)
    — and each bar's late part in red (Redmine's task_late), under the done
    part. Rows are 32px (h-8); relation lines are drawn below the headers.
--}}
@props(['chart', 'lines', 'relationLines' => [], 'zoom' => 2, 'drawProgress' => false, 'drawSelectedColumns' => false, 'selectedColumnTexts' => []])
@php
    $zoom = max(1, min(4, (int) $zoom));
    $headerRows = 1 + ($zoom >= 2 ? 1 : 0) + ($zoom >= 3 ? 1 : 0);
    $pixelsPerDay = [1 => 2, 2 => 4, 3 => 16, 4 => 24][$zoom];
    $timelineWidth = max(580, $chart->totalDays() * $pixelsPerDay);
    $today = \App\Support\Format\DateTimes::today();
@endphp

<div class="overflow-x-auto rounded-md border border-neutral-200 bg-surface" data-gantt-zoom="{{ $zoom }}" data-scroll-x>
    <div class="flex">
        <div class="w-80 shrink-0 border-r border-neutral-200">
            <div class="border-b border-neutral-200 bg-neutral-50" style="height: {{ $headerRows * 32 }}px"></div>
            @foreach ($lines as $line)
                <div wire:key="label-{{ $line['kind'] }}-{{ $line['project']->id }}-{{ $line['row']->id ?? $line['version']->id ?? 0 }}"
                    class="flex h-8 items-center border-b border-neutral-100 px-2 text-sm"
                    style="padding-left: {{ 8 + $line['depth'] * 16 }}px">
                    @if ($line['kind'] === 'project')
                        <a href="{{ route('projects.show', $line['project']) }}" class="truncate font-semibold text-neutral-900 hover:underline" data-gantt-project="{{ $line['project']->id }}" title="{{ $line['project']->name }}">
                            {{ $line['project']->name }}
                        </a>
                    @elseif ($line['kind'] === 'issue')
                        <a href="{{ route('issues.show', [$line['project'], $line['row']->id]) }}" class="truncate text-brand-bold hover:underline" title="{{ $line['row']->trackerName }} #{{ $line['row']->id }}: {{ $line['row']->subject }}">
                            {{ $line['row']->trackerName }} #{{ $line['row']->id }}: {{ $line['row']->subject }}
                        </a>
                        @if ($drawSelectedColumns && ($selectedColumnTexts[$line['row']->id] ?? '') !== '')
                            <span class="ml-1 min-w-0 truncate text-xs text-neutral-400" data-gantt-selected-columns>
                                {{ $selectedColumnTexts[$line['row']->id] }}
                            </span>
                        @endif
                    @else
                        <span class="truncate text-neutral-700" title="{{ $line['version']->name }}">◆ {{ $line['version']->name }}</span>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="relative flex-1" style="min-width: {{ $timelineWidth }}px">
            <div class="relative h-8 border-b border-neutral-200 bg-neutral-50 text-xs text-neutral-500">
                @foreach ($chart->monthBands() as $band)
                    <div class="absolute top-0 flex h-8 items-center overflow-hidden border-l border-neutral-200 pl-1"
                        style="left: {{ $band['leftPercent'] }}%; width: {{ $band['widthPercent'] }}%" title="{{ $band['label'] }}">
                        {{ $band['label'] }}
                    </div>
                @endforeach
            </div>
            @if ($zoom >= 2)
                <div class="relative h-8 border-b border-neutral-200 bg-neutral-50 text-xs text-neutral-500" data-gantt-weeks>
                    @foreach ($chart->weekBands() as $band)
                        <div class="absolute top-0 flex h-8 items-center overflow-hidden border-l border-neutral-200 pl-1"
                            style="left: {{ $band['leftPercent'] }}%; width: {{ $band['widthPercent'] }}%" title="{{ $band['label'] }}">
                            {{ $band['label'] }}
                        </div>
                    @endforeach
                </div>
            @endif
            @if ($zoom >= 3)
                <div class="relative h-8 border-b border-neutral-200 bg-neutral-50 text-xs text-neutral-500" data-gantt-days>
                    @foreach ($chart->dayBands() as $band)
                        <div class="absolute top-0 flex h-8 flex-col items-center justify-center overflow-hidden border-l border-neutral-200 leading-tight {{ $band['nonWorking'] ? 'bg-neutral-100' : '' }}"
                            style="left: {{ $band['leftPercent'] }}%; width: {{ $band['widthPercent'] }}%" title="{{ $band['label'] }}">
                            <span>{{ $band['label'] }}</span>
                            @if ($zoom >= 4)
                                <span>{{ $band['weekday'] }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @foreach ($lines as $line)
                <div wire:key="timeline-{{ $line['kind'] }}-{{ $line['project']->id }}-{{ $line['row']->id ?? $line['version']->id ?? 0 }}"
                    class="relative h-8 border-b border-neutral-100 {{ $line['kind'] === 'project' ? 'bg-neutral-50' : '' }}">
                    @if ($line['kind'] === 'issue' && $line['row']->hasDateRange())
                        @php $row = $line['row']; $late = $chart->lateWidthPercent($row, $today); $barWidth = $chart->barWidthPercent($row); @endphp
                        <div class="absolute top-1.5 h-5 overflow-hidden rounded {{ $row->isClosed ? 'bg-neutral-400' : 'bg-brand' }}"
                            style="left: {{ $chart->barLeftPercent($row) }}%; width: {{ $barWidth }}%"
                            title="{{ $row->subject }} ({{ \App\Support\Format\DateTimes::date($row->startDate) }} 〜 {{ \App\Support\Format\DateTimes::date($row->dueDate) }}, {{ $row->doneRatio }}%)">
                            @if ($late > 0)
                                <div class="absolute inset-y-0 left-0 bg-danger-subtle" data-gantt-late style="width: {{ $barWidth > 0 ? min(100, $late / $barWidth * 100) : 0 }}%"></div>
                            @endif
                            @if ($drawProgress)
                                <div class="relative h-full rounded bg-brand-bold" style="width: {{ $row->doneRatio }}%"></div>
                            @endif
                        </div>
                    @elseif ($line['kind'] === 'version')
                        @php $version = $line['version']; $percent = round($version->asSeenBy(auth()->user())->completedPercent()); @endphp
                        <div class="absolute top-1 flex h-6 -translate-x-1/2 items-center gap-1 text-warning"
                            style="left: {{ $chart->versionMarkerLeftPercent($version) }}%"
                            title="{{ $version->name }} ({{ \App\Support\Format\DateTimes::date($version->due_date) }}, {{ $percent }}%)">
                            <span class="text-lg leading-none">◆</span>
                            <span class="text-xs text-neutral-500">{{ $percent }}%</span>
                        </div>
                    @endif
                </div>
            @endforeach

            @if ($relationLines !== [])
                <svg class="pointer-events-none absolute left-0" style="top: {{ $headerRows * 32 }}px; width: 100%; height: {{ count($lines) * 32 }}px">
                    @foreach ($relationLines as $relationLine)
                        <line wire:key="relation-line-{{ $loop->index }}"
                            x1="{{ $relationLine['x1'] }}%" y1="{{ $relationLine['y1'] }}"
                            x2="{{ $relationLine['x2'] }}%" y2="{{ $relationLine['y2'] }}"
                            stroke="{{ $relationLine['color'] }}" stroke-width="1.5" />
                    @endforeach
                </svg>
            @endif
        </div>
    </div>
</div>
