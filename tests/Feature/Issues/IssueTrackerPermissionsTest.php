<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IncomingMailService;
use App\Support\Mail\ParsedIncomingMail;
use Livewire\Livewire;

/**
 * A1-27c: add_issues / edit_issues / add_issue_notes / delete_issues limited
 * to some trackers (Redmine's user_tracker_permission? and
 * allowed_target_trackers). The user sees every tracker; $limits maps a
 * permission to the tracker names it is limited to.
 *
 * @param  array<int, string>  $permissions
 * @param  array<string, array<int, string>>  $limits
 * @return object{project: Project, user: User, bug: Tracker, feature: Tracker, bugIssue: Issue, featureIssue: Issue, status: IssueStatus, priority: Enumeration}
 */
function trackerPermissionScenario(array $permissions, array $limits): object
{
    $project = Project::factory()->create(['is_public' => false]);
    $trackers = [
        'Bug' => Tracker::factory()->create(['name' => 'Bug', 'position' => 1]),
        'Feature' => Tracker::factory()->create(['name' => 'Feature', 'position' => 2]),
    ];
    $project->trackers()->attach(array_map(fn (Tracker $tracker) => $tracker->id, array_values($trackers)));
    $status = IssueStatus::factory()->create(['is_closed' => false]);
    $priority = Enumeration::factory()->create(['is_default' => true]);
    $user = User::factory()->create(['email' => 'sender@example.com']);

    $factory = Role::factory();

    foreach ($limits as $permission => $names) {
        $factory = $factory->limitedToTrackers($permission, array_map(fn (string $name) => $trackers[$name]->id, $names));
    }

    Member::factory()->for($project)->for($user)->create()->roles()->attach($factory->create(['permissions' => ['view_issues', ...$permissions]]));

    $make = fn (Tracker $tracker) => Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => $status->id,
        'priority_id' => $priority->id,
    ]);

    return (object) [
        'project' => $project,
        'user' => $user,
        'bug' => $trackers['Bug'],
        'feature' => $trackers['Feature'],
        'status' => $status,
        'priority' => $priority,
        'bugIssue' => $make($trackers['Bug']),
        'featureIssue' => $make($trackers['Feature']),
    ];
}

test('edit_issues limited to a tracker only allows editing issues of that tracker', function () {
    $s = trackerPermissionScenario(['edit_issues'], ['edit_issues' => ['Bug']]);

    expect($s->user->can('update', $s->bugIssue))->toBeTrue()
        ->and($s->user->can('update', $s->featureIssue))->toBeFalse();

    Livewire::actingAs($s->user)->test('issues.form', ['project' => $s->project, 'issue' => $s->featureIssue])->assertForbidden();

    $this->withHeaders(['X-Redmine-API-Key' => $s->user->regenerateApiKey()])
        ->putJson("/api/v1/issues/{$s->featureIssue->id}", ['subject' => 'Changed'])
        ->assertForbidden();
});

test('edit_own_issues is not limited by the tracker table', function () {
    $s = trackerPermissionScenario(['edit_own_issues'], ['edit_issues' => []]);
    $s->featureIssue->update(['author_id' => $s->user->id]);

    expect($s->user->can('update', $s->featureIssue))->toBeTrue()
        ->and($s->user->can('update', $s->bugIssue))->toBeFalse();
});

test('add_issue_notes limited to a tracker only allows commenting on that tracker', function () {
    $s = trackerPermissionScenario(['add_issue_notes'], ['add_issue_notes' => ['Bug']]);

    expect($s->user->can('addNotes', $s->bugIssue))->toBeTrue()
        ->and($s->user->can('addNotes', $s->featureIssue))->toBeFalse();

    Livewire::actingAs($s->user)->test('issues.show', ['project' => $s->project, 'issue' => $s->featureIssue])
        ->set('comment', 'Hello')
        ->call('addComment')
        ->assertForbidden();
});

test('delete_issues limited to a tracker only allows deleting issues of that tracker', function () {
    $s = trackerPermissionScenario(['delete_issues'], ['delete_issues' => ['Bug']]);

    expect($s->user->can('delete', $s->bugIssue))->toBeTrue()
        ->and($s->user->can('delete', $s->featureIssue))->toBeFalse();

    $this->withHeaders(['X-Redmine-API-Key' => $s->user->regenerateApiKey()])
        ->deleteJson("/api/v1/issues/{$s->featureIssue->id}")
        ->assertForbidden();

    expect(Issue::query()->whereKey($s->featureIssue->id)->exists())->toBeTrue();
});

test('the new issue form only offers and accepts the trackers add_issues allows', function () {
    $s = trackerPermissionScenario(['add_issues'], ['add_issues' => ['Feature']]);

    $component = Livewire::actingAs($s->user)->test('issues.form', ['project' => $s->project]);

    expect($component->instance()->projectTrackers->pluck('id')->all())->toBe([$s->feature->id])
        ->and($component->get('tracker_id'))->toBe($s->feature->id);

    $component->set('tracker_id', $s->bug->id)
        ->set('subject', 'Sneaky bug')
        ->set('priority_id', $s->priority->id)
        ->call('save')
        ->assertHasErrors('tracker_id');

    expect(Issue::query()->where('subject', 'Sneaky bug')->exists())->toBeFalse();
});

test('a role whose add_issues trackers are not used by the project cannot create issues there', function () {
    $s = trackerPermissionScenario(['add_issues'], ['add_issues' => []]);

    expect($s->user->can('create', [Issue::class, $s->project]))->toBeFalse();

    Livewire::actingAs($s->user)->test('issues.form', ['project' => $s->project])->assertForbidden();
});

test('editing keeps the current tracker and otherwise offers only the add_issues trackers', function () {
    $s = trackerPermissionScenario(['edit_issues'], []);

    // No add_issues at all: the tracker can't be changed (Redmine keeps only the current one).
    $trackers = Livewire::actingAs($s->user)->test('issues.form', ['project' => $s->project, 'issue' => $s->featureIssue])->instance()->projectTrackers;

    expect($trackers->pluck('id')->all())->toBe([$s->feature->id]);
});

test('bulk edit, the context menu, move and copy only offer the add_issues trackers', function () {
    $s = trackerPermissionScenario(['edit_issues', 'add_issues', 'move_issues', 'copy_issues'], ['add_issues' => ['Bug']]);
    $target = Project::factory()->create(['is_public' => false]);
    $target->trackers()->attach([$s->bug->id, $s->feature->id]);
    Member::factory()->for($target)->for($s->user)->create()->roles()->attach(
        Role::factory()->limitedToTrackers('add_issues', [$s->feature->id])->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $list = Livewire::actingAs($s->user)->test('issues.index', ['project' => $s->project])
        ->set('selected', [(string) $s->bugIssue->id])
        ->set('bulkMoveToProjectId', $target->id)
        ->set('bulkCopyToProjectId', $target->id);

    expect($list->instance()->bulkTrackers->pluck('id')->all())->toBe([$s->bug->id])
        ->and($list->instance()->bulkMoveTargetTrackers->pluck('id')->all())->toBe([$s->feature->id])
        ->and($list->instance()->bulkCopyTargetTrackers->pluck('id')->all())->toBe([$s->feature->id]);

    $list->set('bulkTrackerId', $s->feature->id)->call('applyBulkEdit')->assertHasErrors('bulkTrackerId');

    expect($s->bugIssue->fresh()->tracker_id)->toBe($s->bug->id);

    $show = Livewire::actingAs($s->user)->test('issues.show', ['project' => $s->project, 'issue' => $s->bugIssue])->set('moveToProjectId', $target->id);

    expect($show->instance()->moveTargetTrackers->pluck('id')->all())->toBe([$s->feature->id]);
});

test('copying needs an allowed tracker in the target project', function () {
    $s = trackerPermissionScenario(['copy_issues', 'add_issues'], ['add_issues' => []]);

    expect($s->user->can('copy', [$s->bugIssue, $s->project]))->toBeFalse();
});

test('the REST API refuses creating or retyping an issue with a tracker add_issues does not allow', function () {
    $s = trackerPermissionScenario(['add_issues', 'edit_issues'], ['add_issues' => ['Bug']]);
    $key = $s->user->regenerateApiKey();

    $this->withHeaders(['X-Redmine-API-Key' => $key])
        ->postJson("/api/v1/projects/{$s->project->id}/issues", ['tracker_id' => $s->feature->id, 'priority_id' => $s->priority->id, 'subject' => 'Nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tracker_id');

    $this->withHeaders(['X-Redmine-API-Key' => $key])
        ->postJson("/api/v1/projects/{$s->project->id}/issues", ['tracker_id' => $s->bug->id, 'priority_id' => $s->priority->id, 'subject' => 'Yes'])
        ->assertCreated();

    $this->withHeaders(['X-Redmine-API-Key' => $key])
        ->putJson("/api/v1/issues/{$s->bugIssue->id}", ['tracker_id' => $s->feature->id])
        ->assertUnprocessable();

    // Keeping the current tracker is always fine.
    $this->withHeaders(['X-Redmine-API-Key' => $key])
        ->putJson("/api/v1/issues/{$s->featureIssue->id}", ['tracker_id' => $s->feature->id, 'subject' => 'Kept'])
        ->assertOk();
});

test('incoming mail only creates issues with an allowed tracker and replies need add_issue_notes on the tracker', function () {
    $s = trackerPermissionScenario(['add_issues', 'add_issue_notes'], ['add_issues' => ['Bug'], 'add_issue_notes' => ['Bug']]);
    Setting::set('incoming_mail_default_project_id', $s->project->id);
    Setting::set('incoming_mail_default_status_id', $s->status->id);
    $service = app(IncomingMailService::class);

    Setting::set('incoming_mail_default_tracker_id', $s->feature->id);
    expect($service->createIssueFromMail(new ParsedIncomingMail(subject: 'Via mail', body: 'Body', fromEmail: $s->user->email)))->toBeNull();

    Setting::set('incoming_mail_default_tracker_id', $s->bug->id);
    expect($service->createIssueFromMail(new ParsedIncomingMail(subject: 'Via mail', body: 'Body', fromEmail: $s->user->email))?->tracker_id)->toBe($s->bug->id);

    expect($service->createIssueFromMail(new ParsedIncomingMail(subject: "[{$s->project->identifier} #{$s->featureIssue->id}] Re", body: 'Note', fromEmail: $s->user->email)))->toBeNull();

    // A notes-only sender can comment on an allowed tracker, but keyword lines don't change anything.
    $reply = $service->createIssueFromMail(new ParsedIncomingMail(subject: "[{$s->project->identifier} #{$s->bugIssue->id}] Re", body: "Tracker: Feature\n\nA note", fromEmail: $s->user->email));

    expect($reply?->id)->toBe($s->bugIssue->id)
        ->and($s->bugIssue->fresh()->tracker_id)->toBe($s->bug->id)
        ->and($s->bugIssue->fresh()->journals()->latest('id')->first()?->notes)->toContain('A note');
});
