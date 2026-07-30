<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateJournalRequest;
use App\Http\Resources\Api\V1\JournalResource;
use App\Models\Journal;

/**
 * Matches Redmine's JournalsController#update — a bare edit action for
 * note text, not a full CRUD resource: journals are created exclusively
 * as a side effect of an issue update (there is no POST /journals), and
 * there's no index/show/destroy either. Redmine's own routes.rb only ever
 * registers :edit/:update for journals — "deleting" a comment in Redmine
 * means editing its notes to blank (any attribute changes recorded in the
 * same journal survive). JournalPolicy already encodes Redmine's
 * editable_by? (edit_own_issue_notes/edit_issue_notes, notes-present,
 * issue and private-note visibility) — this controller is a thin
 * pass-through.
 */
final class JournalController extends Controller
{
    public function update(UpdateJournalRequest $request, Journal $journal): JournalResource
    {
        $journal->update($request->validated());

        return new JournalResource($journal);
    }
}
