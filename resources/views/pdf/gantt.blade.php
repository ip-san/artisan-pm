<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>{{ $documentTitle }}</title>
    <style>
        <x-pdf.cjk-font />
        @page { margin: 16px 20px; size: A4 landscape; }
        body { font-size: 8px; color: #111827; }
        h1 { font-size: 12px; margin: 0 0 8px; }
        {{--
            Every row — label or timeline — is positioned absolute against
            the same .chart container with `top` computed from the same
            row index * ROW_HEIGHT. A mix of absolute (label) and relative
            (row, offset via `top` from its own flow position) positioning
            was tried first and drifted rows further apart on every
            subsequent row (relative `top` stacks on top of the flow
            position instead of replacing it) — confirmed visually by
            rasterizing the PDF, since the row/label mismatch wasn't
            visible in a single-row export or in text-only extraction.
        --}}
        .chart { position: relative; }
        .label { position: absolute; left: 0; width: 220px; height: 16px; line-height: 16px; padding-left: 4px; overflow: hidden; white-space: nowrap; border-bottom: 1px solid #f3f4f6; }
        .label.header { border-bottom: 1px solid #d1d5db; background: #f9fafb; }
        .timeline-row { position: absolute; left: 220px; right: 0; height: 16px; border-bottom: 1px solid #f3f4f6; }
        .timeline-row.header { border-bottom: 1px solid #d1d5db; background: #f9fafb; }
        .band { position: absolute; top: 0; height: 16px; line-height: 16px; padding-left: 2px; border-left: 1px solid #d1d5db; color: #6b7280; }
        .bar { position: absolute; top: 2px; height: 12px; border-radius: 2px; background: #818cf8; }
        .bar.closed { background: #9ca3af; }
        .bar-done { height: 100%; border-radius: 2px; background: #4f46e5; }
        .label.project { font-weight: bold; }
        .timeline-row.project { background: #f9fafb; }
        .relations { position: absolute; left: 220px; right: 0; }
        .relation-segment { position: absolute; }
        .milestone { position: absolute; top: 0; height: 16px; line-height: 16px; color: #b45309; }
    </style>
</head>
<body>
    <h1>{{ $heading }}</h1>

    @php $rowHeight = 16; @endphp

    <div class="chart" style="height: {{ $rowHeight * (count($lines) + 1) }}px">
        <div class="label header" style="top: 0"></div>
        <div class="timeline-row header" style="top: 0">
            @foreach ($chart->monthBands() as $band)
                <div class="band" style="left: {{ $band['leftPercent'] }}%; width: {{ $band['widthPercent'] }}%">{{ $band['label'] }}</div>
            @endforeach
        </div>

        @foreach ($lines as $line)
            @php $top = $rowHeight * ($loop->index + 1); @endphp
            <div class="label {{ $line->kind === 'project' ? 'project' : '' }}" style="top: {{ $top }}px; padding-left: {{ 4 + $line->depth * 10 }}px">
                {{ $line->kind === 'version' ? '◆ ' : '' }}{{ $line->label }}
            </div>
            <div class="timeline-row {{ $line->kind === 'project' ? 'project' : '' }}" style="top: {{ $top }}px">
                @if ($line->kind === 'issue' && $line->row->hasDateRange())
                    <div class="bar {{ $line->row->isClosed ? 'closed' : '' }}"
                        style="left: {{ $chart->barLeftPercent($line->row) }}%; width: {{ $chart->barWidthPercent($line->row) }}%">
                        <div class="bar-done" style="width: {{ $line->row->doneRatio }}%"></div>
                    </div>
                @elseif ($line->kind === 'version')
                    <div class="milestone" style="left: {{ $chart->versionMarkerLeftPercent($line->version) }}%">◆ {{ $line->versionPercent }}%</div>
                @endif
            </div>
        @endforeach

        @if ($relationSegments !== [])
            <div class="relations" style="top: {{ $rowHeight }}px; height: {{ $rowHeight * count($lines) }}px">
                @foreach ($relationSegments as $segment)
                    <div class="relation-segment" style="left: {{ $segment['left'] }}; width: {{ $segment['width'] }}; top: {{ $segment['top'] }}; height: {{ $segment['height'] }}; background: {{ $segment['color'] }}"></div>
                @endforeach
            </div>
        @endif
    </div>
</body>
</html>
