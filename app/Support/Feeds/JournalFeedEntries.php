<?php

declare(strict_types=1);

namespace App\Support\Feeds;

use App\Mail\IssueNotificationMail;
use App\Models\CustomField;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use App\Support\Query\CustomFieldVisibility;
use Illuminate\Support\Collection;

/**
 * Shared by IssueChangesAtomController (many issues' journals) and
 * IssueJournalAtomController (one issue's own journals, Redmine's
 * `issues/{id}.atom` — `journals/index` rendered for a single issue): which
 * journals a reader may see, and how to describe one as a feed entry's list
 * of field changes.
 */
final class JournalFeedEntries
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * $journals filtered to those the reader may read (private notes) and
     * that have something to show (a note, or at least one visible detail)
     * — matches IssueChangesAtomController's own filter, factored out so
     * the single-issue feed applies the exact same rules.
     *
     * @param  Collection<int, Journal>  $journals
     * @param  Collection<int, CustomField>  $customFields
     * @return Collection<int, Journal>
     */
    public function visible(Collection $journals, ?User $user, Collection $customFields): Collection
    {
        $visibility = CustomFieldVisibility::for($user);

        return $journals
            ->filter(fn (Journal $journal) => $this->mayRead($journal, $user))
            ->reject(fn (Journal $journal) => blank($journal->notes)
                && $journal->details->reject(fn (JournalDetail $detail) => $this->isHiddenCustomField($detail, $journal, $customFields, $visibility))->isEmpty())
            ->values();
    }

    public function mayRead(Journal $journal, ?User $user): bool
    {
        if (! $journal->private_notes) {
            return true;
        }

        return $user !== null
            && ($journal->user_id === $user->id || $this->authorization->can($user, 'view_private_notes', $journal->issue->project));
    }

    public function isHiddenCustomField(JournalDetail $detail, Journal $journal, Collection $customFields, CustomFieldVisibility $visibility): bool
    {
        if ($detail->property !== 'cf') {
            return false;
        }

        $field = $customFields->get((int) $detail->prop_key);

        return $field === null || ! $visibility->isVisibleIn($field, $journal->issue->project);
    }

    /**
     * One line per visible detail: "label: old → new". A custom field
     * change shows only when the reader may see that field in the issue's
     * project (Redmine's Journal#visible_details).
     *
     * @param  Collection<int, CustomField>  $customFields
     * @return array<int, string>
     */
    public function describe(Journal $journal, Collection $customFields, CustomFieldVisibility $visibility): array
    {
        return $journal->details
            ->whereIn('property', ['attr', 'cf', 'attachment', 'relation'])
            ->reject(fn (JournalDetail $detail) => $this->isHiddenCustomField($detail, $journal, $customFields, $visibility))
            ->map(function (JournalDetail $detail) use ($customFields): string {
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
