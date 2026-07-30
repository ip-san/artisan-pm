<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>{{ $project->identifier }}-{{ $issue->id }}</title>
    <style>
        <x-pdf.cjk-font />
        @page { margin: 20px 24px; }
        body { font-size: 10px; color: #111827; }
        h1 { font-size: 13px; margin: 0 0 2px; }
        .meta { color: #6b7280; font-size: 9px; margin-bottom: 10px; }
        table.attrs { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.attrs td { border: 1px solid #d1d5db; padding: 4px 6px; vertical-align: top; width: 25%; }
        table.attrs td.label { background: #f3f4f6; font-weight: bold; width: 15%; }
        .section-title { font-weight: bold; font-size: 11px; margin: 10px 0 4px; border-bottom: 1px solid #d1d5db; padding-bottom: 2px; }
        .prose { font-size: 10px; line-height: 1.5; }
        .prose p { margin: 0 0 6px; }
        .note { border: 1px solid #e5e7eb; padding: 6px; margin-bottom: 6px; }
        .note .note-meta { color: #6b7280; font-size: 9px; margin-bottom: 3px; }
    </style>
</head>
<body>
    <h1>{{ $project->name }} - {{ $issue->tracker->name }} #{{ $issue->id }}: {{ $issue->subject }}</h1>
    <div class="meta">{{ $issue->created_at?->format('Y-m-d H:i') }} - {{ $issue->author->name }}</div>

    <table class="attrs">
        <tr>
            <td class="label">ステータス</td><td>{{ $issue->status->name }}</td>
            <td class="label">優先度</td><td>{{ $issue->priority->name }}</td>
        </tr>
        <tr>
            <td class="label">担当者</td><td>{{ $issue->assignedTo?->name ?? '-' }}</td>
            <td class="label">カテゴリ</td><td>{{ $issue->category?->name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">対象バージョン</td><td>{{ $issue->fixedVersion?->name ?? '-' }}</td>
            <td class="label">進捗率</td><td>{{ $issue->done_ratio }}%</td>
        </tr>
        <tr>
            <td class="label">開始日</td><td>{{ $issue->start_date?->toDateString() ?? '-' }}</td>
            <td class="label">期日</td><td>{{ $issue->due_date?->toDateString() ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">予定工数</td><td>{{ $issue->estimated_hours ?? '-' }}</td>
            <td class="label">親課題</td><td>{{ $issue->parent ? "#{$issue->parent->id} {$issue->parent->subject}" : '-' }}</td>
        </tr>
        @foreach ($customFieldValues as $entry)
            <tr>
                <td class="label">{{ $entry['field']->name }}</td><td colspan="3">{{ $entry['value'] ?? '-' }}</td>
            </tr>
        @endforeach
    </table>

    @if ($descriptionHtml !== null)
        <div class="section-title">説明</div>
        <div class="prose">{!! $descriptionHtml !!}</div>
    @endif

    @if ($notes->isNotEmpty())
        <div class="section-title">履歴</div>
        @foreach ($notes as $entry)
            <div class="note">
                <div class="note-meta">{{ $entry['journal']->user->name }} — {{ $entry['journal']->created_at->format('Y-m-d H:i') }}</div>
                <div class="prose">{!! $entry['html'] !!}</div>
            </div>
        @endforeach
    @endif
</body>
</html>
