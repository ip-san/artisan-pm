<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\IssueNotificationMail;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use App\Models\Setting;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Redmine's JournalsController#index: an Atom feed of the latest issue
 * changes — the notes and field changes of each journal, newest first — for
 * one project or, without one, every project the reader may see. Only
 * journals of issues the reader may see; private notes only for those who
 * may read them or wrote them. Redmine caps this feed at 25 entries.
 */
final class IssueChangesAtomController extends Controller
{
    private const int LIMIT = 25;

    public function __invoke(Request $request, ?Project $project = null): Response
    {
        $user = $request->user();
        $authorization = app(AuthorizationService::class);

        if ($project !== null) {
            Gate::authorize('viewAny', [Issue::class, $project]);
        }

        $projects = ($project !== null ? collect([$project]) : Project::query()->get())
            ->filter(fn (Project $candidate) => $authorization->can($user, 'view_issues', $candidate))
            ->values();

        $journals = Journal::query()
            ->whereIn('issue_id', Issue::query()->select('id')->visibleToAcrossProjects($user, $projects))
            ->with(['issue.project', 'issue.tracker', 'user', 'details'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::LIMIT * 4)
            ->get()
            ->filter(fn (Journal $journal) => $this->mayRead($journal, $user, $authorization))
            ->reject(fn (Journal $journal) => $journal->isEmpty())
            ->take(self::LIMIT)
            ->values();

        $customFieldNames = CustomField::query()->pluck('name', 'id');

        $xml = view('feeds.issue-changes', [
            'journals' => $journals,
            'title' => ($project !== null ? $project->name : (string) Setting::get('app_title', config('app.name'))).': 変更の詳細',
            'alternateUrl' => $project !== null ? route('issues.index', $project) : route('issues.global-index'),
            'details' => fn (Journal $journal) => $this->describe($journal, $customFieldNames),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }

    private function mayRead(Journal $journal, ?\App\Models\User $user, AuthorizationService $authorization): bool
    {
        if (! $journal->private_notes) {
            return true;
        }

        return $user !== null
            && ($journal->user_id === $user->id || $authorization->can($user, 'view_private_notes', $journal->issue->project));
    }

    /**
     * One line per visible detail: "label: old → new".
     *
     * @param  Collection<int, string>  $customFieldNames
     * @return array<int, string>
     */
    private function describe(Journal $journal, Collection $customFieldNames): array
    {
        return $journal->details
            ->whereIn('property', ['attr', 'cf', 'attachment', 'relation'])
            ->map(function ($detail) use ($customFieldNames): string {
                $label = match ($detail->property) {
                    'cf' => $customFieldNames[(int) $detail->prop_key] ?? $detail->prop_key,
                    'attachment' => '添付ファイル',
                    'relation' => IssueNotificationMail::RELATION_LABELS[$detail->prop_key] ?? $detail->prop_key,
                    default => IssueNotificationMail::ATTRIBUTE_LABELS[$detail->prop_key] ?? $detail->prop_key,
                };

                $old = $detail->old_value;
                $new = $detail->new_value;

                return match (true) {
                    $old !== null && $new !== null => "{$label}: {$old} → {$new}",
                    $new !== null => "{$label}: {$new} を追加",
                    default => "{$label}: {$old} を削除",
                };
            })
            ->values()
            ->all();
    }
}
