<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\IssueNotificationMail;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use App\Support\Query\CustomFieldVisibility;
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

        $customFields = CustomField::query()->with('roles')->get()->keyBy('id');
        $visibility = CustomFieldVisibility::for($user);

        $journals = Journal::query()
            ->whereIn('issue_id', Issue::query()->select('id')->visibleToAcrossProjects($user, $projects))
            ->with(['issue.project', 'issue.tracker', 'user', 'details'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::LIMIT * 4)
            ->get()
            ->filter(fn (Journal $journal) => $this->mayRead($journal, $user, $authorization))
            ->reject(fn (Journal $journal) => blank($journal->notes)
                && $journal->details->reject(fn (JournalDetail $detail) => $this->isHiddenCustomField($detail, $journal, $customFields, $visibility))->isEmpty())
            ->take(self::LIMIT)
            ->values();

        $xml = view('feeds.issue-changes', [
            'journals' => $journals,
            'title' => ($project !== null ? $project->name : (string) Setting::get('app_title', config('app.name'))).': '.__('変更の詳細'),
            'alternateUrl' => $project !== null ? route('issues.index', $project) : route('issues.global-index'),
            'details' => fn (Journal $journal) => $this->describe($journal, $customFields, $visibility),
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }

    /**
     * @param  Collection<int, CustomField>  $customFields
     */
    private function isHiddenCustomField(JournalDetail $detail, Journal $journal, Collection $customFields, CustomFieldVisibility $visibility): bool
    {
        if ($detail->property !== 'cf') {
            return false;
        }

        $field = $customFields->get((int) $detail->prop_key);

        return $field === null || ! $visibility->isVisibleIn($field, $journal->issue->project);
    }

    private function mayRead(Journal $journal, ?User $user, AuthorizationService $authorization): bool
    {
        if (! $journal->private_notes) {
            return true;
        }

        return $user !== null
            && ($journal->user_id === $user->id || $authorization->can($user, 'view_private_notes', $journal->issue->project));
    }

    /**
     * One line per visible detail: "label: old → new". A custom field
     * change shows only when the reader may see that field in the issue's
     * project (Redmine's Journal#visible_details).
     *
     * @param  Collection<int, CustomField>  $customFields
     * @return array<int, string>
     */
    private function describe(Journal $journal, Collection $customFields, CustomFieldVisibility $visibility): array
    {
        return $journal->details
            ->whereIn('property', ['attr', 'cf', 'attachment', 'relation'])
            ->reject(fn (JournalDetail $detail) => $this->isHiddenCustomField($detail, $journal, $customFields, $visibility))
            ->map(function ($detail) use ($customFields): string {
                $label = match ($detail->property) {
                    'cf' => $customFields->get((int) $detail->prop_key)?->name ?? $detail->prop_key,
                    'attachment' => __('添付ファイル'),
                    'relation' => IssueNotificationMail::relationLabels()[$detail->prop_key] ?? $detail->prop_key,
                    default => IssueNotificationMail::attributeLabels()[$detail->prop_key] ?? $detail->prop_key,
                };

                $old = $detail->displayValue($detail->old_value);
                $new = $detail->displayValue($detail->new_value);

                return match (true) {
                    $old !== null && $new !== null => "{$label}: {$old} → {$new}",
                    $new !== null => __(':label: :value を追加', ['label' => $label, 'value' => $new]),
                    default => __(':label: :value を削除', ['label' => $label, 'value' => $old]),
                };
            })
            ->values()
            ->all();
    }
}
