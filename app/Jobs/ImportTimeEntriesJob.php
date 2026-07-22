<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\TimeEntry;
use App\Models\TimeEntryImport;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Reads the CSV stored for a TimeEntryImport, maps each row's columns per
 * the import's stored column_mapping, and creates one TimeEntry per row.
 * A row that can't be mapped or created is recorded in `errors` and
 * skipped; the rest of the file still imports. Mirrors ImportIssuesJob's
 * shape closely.
 */
final class ImportTimeEntriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly TimeEntryImport $import,
    ) {}

    public function handle(AuthorizationService $authorization): void
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

        // Only an importer holding edit_time_entries may attribute rows to
        // a mapped "user" column — matches this app's existing "log time
        // for others" gate on the manual entry form (time-entries/form's
        // canManageOthers), which stands in for Redmine's dedicated
        // log_time_for_other_users permission (not modeled here).
        $canLogForOthers = $authorization->can($this->import->user, 'edit_time_entries', $this->import->project);
        $defaultActivity = $this->import->project->activities(includeInactive: true)->firstWhere('is_default', true);

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 for zero-index, +1 for the header row

            try {
                $record = array_combine($header, array_pad($row, count($header), null));

                TimeEntry::create($this->mapRowToAttributes($record, $mapping, $defaultActivity, $canLogForOthers));

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
     * @return array<string, mixed>
     */
    private function mapRowToAttributes(array $record, array $mapping, ?Enumeration $defaultActivity, bool $canLogForOthers): array
    {
        $hours = $this->mapped($record, $mapping, 'hours');

        if ($hours === null || ! is_numeric($hours) || (float) $hours <= 0) {
            throw new RuntimeException('時間が空または不正です。');
        }

        $spentOn = $this->mapped($record, $mapping, 'spent_on');

        if ($spentOn === null || $spentOn === '') {
            throw new RuntimeException('日付が空です。');
        }

        $activityName = $this->mapped($record, $mapping, 'activity');
        $activity = ($activityName !== null
            ? $this->import->project->activities(includeInactive: true)->firstWhere('name', $activityName)
            : null) ?? $defaultActivity;

        if ($activity === null) {
            throw new RuntimeException('作業分類を特定できません。');
        }

        $issueRef = $this->mapped($record, $mapping, 'issue');
        $issue = $issueRef !== null
            ? Issue::query()->where('project_id', $this->import->project_id)->find((int) ltrim($issueRef, '#'))
            : null;

        if ($issueRef !== null && $issue === null) {
            throw new RuntimeException("課題 {$issueRef} が見つかりません。");
        }

        // Scoped to project members — a mapped "user" column matching some
        // other user in the system (unrelated to this project) must not be
        // able to receive attributed time via a crafted CSV row, same
        // protection ImportIssuesJob applies to assigned_to.
        $userEmail = $canLogForOthers ? $this->mapped($record, $mapping, 'user') : null;
        $user = $userEmail !== null
            ? User::query()
                ->whereHas('memberships', fn ($query) => $query->where('project_id', $this->import->project_id))
                ->where('email', $userEmail)
                ->first()
            : null;

        return [
            'project_id' => $this->import->project_id,
            'issue_id' => $issue?->id,
            'user_id' => $user !== null ? $user->id : $this->import->user_id,
            'activity_id' => $activity->id,
            'hours' => (float) $hours,
            'spent_on' => $spentOn,
            'comments' => $this->mapped($record, $mapping, 'comments'),
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
