<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EnumerationType;
use App\Enums\ImportStatus;
use App\Models\Enumeration;
use App\Models\IssueImport;
use App\Models\IssueStatus;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Reads the CSV stored for an IssueImport, maps each row's columns per
 * the import's stored column_mapping, and creates one Issue per row via
 * IssueService — the same path a hand-created issue goes through. A row
 * that can't be mapped or created is recorded in `errors` and skipped;
 * the rest of the file still imports.
 */
final class ImportIssuesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly IssueImport $import,
    ) {}

    public function handle(IssueService $issueService): void
    {
        $this->import->update(['status' => ImportStatus::Processing]);

        $disk = Storage::disk('local');

        if (! $disk->exists($this->import->file_path)) {
            $this->import->update([
                'status' => ImportStatus::Failed,
                'errors' => [['row' => 0, 'message' => 'アップロードされたファイルが見つかりません。']],
            ]);

            return;
        }

        $rows = array_map('str_getcsv', file($disk->path($this->import->file_path)));
        $header = array_shift($rows) ?? [];

        $this->import->update(['total_rows' => count($rows)]);

        $mapping = $this->import->column_mapping;
        $errors = [];
        $imported = 0;

        $defaults = [
            'tracker' => $this->import->project->trackers()->orderBy('position')->first(),
            'status' => IssueStatus::query()->orderBy('position')->first(),
            'priority' => Enumeration::query()->ofType(EnumerationType::IssuePriority)->where('is_default', true)->first(),
        ];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 for zero-index, +1 for the header row

            try {
                $record = array_combine($header, array_pad($row, count($header), null));

                $issueService->create(
                    $this->mapRowToAttributes($record, $mapping, $defaults),
                    $this->import->user,
                );

                $imported++;
            } catch (Throwable $e) {
                $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
            }

            $this->import->increment('processed_rows');
        }

        $this->import->update([
            'status' => ImportStatus::Completed,
            'imported_count' => $imported,
            'failed_count' => count($errors),
            'errors' => $errors,
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $mapping
     * @param  array<string, Tracker|IssueStatus|Enumeration|null>  $defaults
     * @return array<string, mixed>
     */
    private function mapRowToAttributes(array $record, array $mapping, array $defaults): array
    {
        $subject = trim((string) $this->mapped($record, $mapping, 'subject'));

        if ($subject === '') {
            throw new RuntimeException('題名が空です。');
        }

        $trackerName = $this->mapped($record, $mapping, 'tracker');
        $tracker = ($trackerName !== null ? Tracker::query()->where('name', $trackerName)->first() : null) ?? $defaults['tracker'];

        $statusName = $this->mapped($record, $mapping, 'status');
        $status = ($statusName !== null ? IssueStatus::query()->where('name', $statusName)->first() : null) ?? $defaults['status'];

        $priorityName = $this->mapped($record, $mapping, 'priority');
        $priority = ($priorityName !== null
            ? Enumeration::query()->ofType(EnumerationType::IssuePriority)->where('name', $priorityName)->first()
            : null) ?? $defaults['priority'];

        if ($tracker === null || $status === null || $priority === null) {
            throw new RuntimeException('トラッカー・ステータス・優先度のいずれかを特定できません。');
        }

        // Scoped to project members — an email matching some other user in
        // the system (unrelated to this project) should not be able to
        // receive an assignment via a crafted CSV row.
        $assigneeEmail = $this->mapped($record, $mapping, 'assigned_to');
        $assignee = $assigneeEmail !== null
            ? User::query()
                ->whereHas('memberships', fn ($query) => $query->where('project_id', $this->import->project_id))
                ->where('email', $assigneeEmail)
                ->first()
            : null;

        return [
            'project_id' => $this->import->project_id,
            'tracker_id' => $tracker->id,
            'status_id' => $status->id,
            'priority_id' => $priority->id,
            'subject' => $subject,
            'description' => $this->mapped($record, $mapping, 'description'),
            'assigned_to_id' => $assignee?->id,
            'start_date' => $this->mapped($record, $mapping, 'start_date') ?: null,
            'due_date' => $this->mapped($record, $mapping, 'due_date') ?: null,
            'done_ratio' => (int) ($this->mapped($record, $mapping, 'done_ratio') ?: 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $mapping
     */
    private function mapped(array $record, array $mapping, string $field): ?string
    {
        $column = $mapping[$field] ?? null;

        if ($column === null || ! array_key_exists($column, $record)) {
            return null;
        }

        $value = $record[$column];

        return $value === null ? null : trim((string) $value);
    }

    public function failed(Throwable $e): void
    {
        $this->import->update(['status' => ImportStatus::Failed]);
    }
}
