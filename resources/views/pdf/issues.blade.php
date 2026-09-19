<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>{{ $project->identifier }}-issues</title>
    <style>
        <x-pdf.cjk-font />
        @page { margin: 16px 20px; size: A4 landscape; }
        body { font-size: 8px; color: #111827; }
        h1 { font-size: 12px; margin: 0 0 4px; }
        p.meta { margin: 0 0 8px; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f3f4f6; text-align: left; border: 1px solid #d1d5db; padding: 2px 4px; }
        td { border: 1px solid #e5e7eb; padding: 2px 4px; vertical-align: top; }
        tr.block td { background: #f9fafb; color: #4b5563; }
    </style>
</head>
<body>
    <h1>{{ $project->name }} - 課題</h1>
    <p class="meta">{{ now()->toDateString() }}時点 / {{ count($rows) }}件@if ($total > count($rows)) (全{{ $total }}件のうち先頭)@endif</p>

    <table>
        <thead>
            <tr>
                <th>#</th>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['id'] }}</td>
                    @foreach ($row['cells'] as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
                @foreach ($row['blocks'] as $label => $value)
                    <tr class="block">
                        <td colspan="{{ count($headings) + 1 }}"><b>{{ $label }}:</b> {{ $value }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</body>
</html>
