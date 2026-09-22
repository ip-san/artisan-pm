<?php

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Support\Reports\IssueReport;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public string $detail = '';

    public string $csvEncoding = 'UTF-8';

    public string $csvSeparator = ',';

    public function mount(Project $project, string $detail): void
    {
        $this->authorize('viewAny', [Issue::class, $project]);

        abort_unless(IssueReport::isDimension($detail), 404);

        $this->project = $project;
        $this->detail = $detail;
    }

    #[Computed]
    public function report(): IssueReport
    {
        return new IssueReport($this->project, auth()->user());
    }

    /**
     * @return Collection<int, IssueStatus>
     */
    #[Computed]
    public function statuses(): Collection
    {
        return IssueStatus::query()->orderBy('position')->get();
    }

    /**
     * @return array<int|string, array<int, int>>
     */
    #[Computed]
    public function counts(): array
    {
        return $this->report->counts($this->detail);
    }

    /**
     * @return array<int, array{key: int|string, label: string}>
     */
    #[Computed]
    public function rows(): array
    {
        return $this->report->gridRows($this->detail, $this->counts);
    }

    /**
     * The row's counts split the way Redmine's details page does: one per
     * status, then open, closed and all.
     *
     * @return array{statuses: array<int, int>, open: int, closed: int, total: int}
     */
    public function rowFigures(int|string $key): array
    {
        $byStatus = $this->counts[$key] ?? [];
        $closedIds = $this->statuses->where('is_closed', true)->pluck('id')->all();

        $figures = ['statuses' => [], 'open' => 0, 'closed' => 0, 'total' => array_sum($byStatus)];

        foreach ($this->statuses as $status) {
            $figures['statuses'][$status->id] = $byStatus[$status->id] ?? 0;
        }

        foreach ($byStatus as $statusId => $count) {
            $figures[in_array($statusId, $closedIds, true) ? 'closed' : 'open'] += $count;
        }

        return $figures;
    }

    /**
     * @return array{statuses: array<int, int>, open: int, closed: int, total: int}
     */
    public function totalFigures(): array
    {
        $totals = ['statuses' => array_fill_keys($this->statuses->pluck('id')->all(), 0), 'open' => 0, 'closed' => 0, 'total' => 0];

        foreach ($this->rows as $row) {
            $figures = $this->rowFigures($row['key']);

            foreach ($figures['statuses'] as $statusId => $count) {
                $totals['statuses'][$statusId] += $count;
            }

            $totals['open'] += $figures['open'];
            $totals['closed'] += $figures['closed'];
            $totals['total'] += $figures['total'];
        }

        return $totals;
    }

    /**
     * Redmine's report-<detail>.csv: a row per dimension value with the count
     * for each status and the open / closed / total columns.
     */
    public function exportCsv(): StreamedResponse
    {
        $this->authorize('viewAny', [Issue::class, $this->project]);

        $encoding = in_array($this->csvEncoding, ['UTF-8', 'SJIS-win'], true) ? $this->csvEncoding : 'UTF-8';
        $separator = in_array($this->csvSeparator, [',', ';', "\t"], true) ? $this->csvSeparator : ',';

        $lines = [array_merge([''], $this->statuses->pluck('name')->all(), ['未完了', '完了', '合計'])];

        foreach ($this->rows as $row) {
            $figures = $this->rowFigures($row['key']);
            $lines[] = array_merge([$row['label']], array_values($figures['statuses']), [$figures['open'], $figures['closed'], $figures['total']]);
        }

        $totals = $this->totalFigures();
        $lines[] = array_merge(['合計'], array_values($totals['statuses']), [$totals['open'], $totals['closed'], $totals['total']]);

        return response()->streamDownload(function () use ($lines, $encoding, $separator): void {
            $handle = fopen('php://output', 'w');

            if ($encoding === 'UTF-8') {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            foreach ($lines as $line) {
                fputcsv($handle, array_map(fn ($value) => $encoding === 'UTF-8' ? (string) $value : mb_convert_encoding((string) $value, $encoding, 'UTF-8'), \App\Support\Export\CsvCell::row($line)), $separator);
            }

            fclose($handle);
        }, "report-{$this->detail}.csv");
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900">{{ $project->name }} — {{ IssueReport::title($detail) }}別の課題</h1>
        <a href="{{ route('issues.report', $project) }}" class="text-sm text-brand-bold hover:underline">課題レポートへ戻る</a>
    </div>

    @if ($this->rows === [])
        <p class="text-sm text-neutral-500">データがありません。</p>
    @else
        @php $totals = $this->totalFigures(); @endphp
        <div class="overflow-x-auto">
            <table class="min-w-full border border-neutral-200 bg-white text-sm">
                <thead>
                    <tr class="border-b border-neutral-200 bg-neutral-50">
                        <th class="px-3 py-2"></th>
                        @foreach ($this->statuses as $status)
                            <th class="px-3 py-2 text-right font-medium text-neutral-700">{{ $status->name }}</th>
                        @endforeach
                        <th class="px-3 py-2 text-right font-semibold text-neutral-900">未完了</th>
                        <th class="px-3 py-2 text-right font-semibold text-neutral-900">完了</th>
                        <th class="px-3 py-2 text-right font-semibold text-neutral-900">合計</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->rows as $row)
                        @php $figures = $this->rowFigures($row['key']); @endphp
                        <tr wire:key="detail-row-{{ $row['key'] }}" class="border-b border-neutral-100">
                            <td class="px-3 py-2 text-neutral-900">{{ $row['label'] }}</td>
                            @foreach ($this->statuses as $status)
                                <td class="px-3 py-2 text-right text-neutral-700">{{ $figures['statuses'][$status->id] }}</td>
                            @endforeach
                            <td class="px-3 py-2 text-right text-neutral-700">{{ $figures['open'] }}</td>
                            <td class="px-3 py-2 text-right text-neutral-700">{{ $figures['closed'] }}</td>
                            <td class="px-3 py-2 text-right font-semibold text-neutral-900">{{ $figures['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-neutral-300 bg-neutral-50 font-semibold">
                        <td class="px-3 py-2">合計</td>
                        @foreach ($this->statuses as $status)
                            <td class="px-3 py-2 text-right">{{ $totals['statuses'][$status->id] }}</td>
                        @endforeach
                        <td class="px-3 py-2 text-right">{{ $totals['open'] }}</td>
                        <td class="px-3 py-2 text-right">{{ $totals['closed'] }}</td>
                        <td class="px-3 py-2 text-right">{{ $totals['total'] }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <select wire:model="csvEncoding" class="rounded-md border-neutral-300 text-xs">
                <option value="UTF-8">UTF-8</option>
                <option value="SJIS-win">Shift_JIS</option>
            </select>
            <select wire:model="csvSeparator" class="rounded-md border-neutral-300 text-xs">
                <option value=",">カンマ</option>
                <option value=";">セミコロン</option>
                <option value="{{ "\t" }}">タブ</option>
            </select>
            <button wire:click="exportCsv" class="rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50">CSVエクスポート</button>
        </div>
    @endif
</div>
