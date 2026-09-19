<?php

use App\Enums\IssueRelationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 * @return array{project: Project, user: User, source: Issue, tracker: Tracker}
 */
function copyOptionsSetup(array $permissions = ['view_issues', 'add_issues', 'add_issue_watchers']): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));
    $source = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'subject' => 'Source',
    ]);

    return compact('project', 'user', 'source', 'tracker');
}

function copyOptionsSave(Project $project, User $user, Issue $source, array $set = []): Issue
{
    $form = Livewire::withQueryParams(['copy_from' => $source->id])->actingAs($user)->test('issues.form', ['project' => $project]);

    foreach ($set as $key => $value) {
        $form->set($key, $value);
    }

    $form->call('save')->assertHasNoErrors();

    return Issue::query()->where('subject', 'Source')->where('id', '!=', $source->id)->latest('id')->firstOrFail();
}

test('a saved copy links back to its source by default (ask, box checked)', function () {
    ['project' => $project, 'user' => $user, 'source' => $source] = copyOptionsSetup();

    $copy = copyOptionsSave($project, $user, $source);

    expect($source->relationsFrom()->where('relation_type', IssueRelationType::CopiedTo->value)->where('issue_to_id', $copy->id)->exists())->toBeTrue();
});

test('unticking the link box on an ask setting skips the relation', function () {
    ['project' => $project, 'user' => $user, 'source' => $source] = copyOptionsSetup();

    copyOptionsSave($project, $user, $source, ['copyLink' => false]);

    expect($source->relationsFrom()->count())->toBe(0);
});

test('link_copied_issue yes always links and no never does, whatever the box says', function () {
    ['project' => $project, 'user' => $user, 'source' => $source] = copyOptionsSetup();

    Setting::set('link_copied_issue', 'no');
    copyOptionsSave($project, $user, $source, ['copyLink' => true]);
    expect($source->relationsFrom()->count())->toBe(0);

    Setting::set('link_copied_issue', 'yes');
    copyOptionsSave($project, $user, $source, ['copyLink' => false]);
    expect($source->relationsFrom()->count())->toBe(1);
});

test('the copy form hides the link and attachment boxes unless the settings ask', function () {
    Storage::fake('local');
    ['project' => $project, 'user' => $user, 'source' => $source] = copyOptionsSetup();
    $source->addMedia(UploadedFile::fake()->create('a.txt', 10))->toMediaCollection('attachments');

    $page = Livewire::withQueryParams(['copy_from' => $source->id])->actingAs($user)->test('issues.form', ['project' => $project]);
    $page->assertSee('コピー元との関連を作る')->assertSee('添付ファイルをコピーする');

    Setting::set('link_copied_issue', 'yes');
    Setting::set('copy_attachments_on_issue_copy', 'no');
    $page = Livewire::withQueryParams(['copy_from' => $source->id])->actingAs($user)->test('issues.form', ['project' => $project]);
    $page->assertDontSee('コピー元との関連を作る')->assertDontSee('添付ファイルをコピーする');
});

test('attachments are copied by default and skipped when the box is cleared or the setting says no', function () {
    Storage::fake('local');
    ['project' => $project, 'user' => $user, 'source' => $source] = copyOptionsSetup();
    $source->addMedia(UploadedFile::fake()->create('a.txt', 10))->toMediaCollection('attachments');

    $copy = copyOptionsSave($project, $user, $source);
    expect($copy->getMedia('attachments'))->toHaveCount(1);

    $withoutAttachments = copyOptionsSave($project, $user, $source, ['copyAttachments' => false]);
    expect($withoutAttachments->getMedia('attachments'))->toHaveCount(0);

    Setting::set('copy_attachments_on_issue_copy', 'no');
    expect(copyOptionsSave($project, $user, $source, ['copyAttachments' => true])->getMedia('attachments'))->toHaveCount(0);
});

test('subtasks and watchers are copied when their boxes are ticked, and offered only when relevant', function () {
    ['project' => $project, 'user' => $user, 'source' => $source, 'tracker' => $tracker] = copyOptionsSetup();
    $watcher = User::factory()->create();
    $source->watchers()->create(['user_id' => $watcher->id]);

    $childless = Livewire::withQueryParams(['copy_from' => $source->id])->actingAs($user)->test('issues.form', ['project' => $project]);
    $childless->assertDontSee('子課題をコピーする')->assertSee('ウォッチャーをコピーする');

    Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'priority_id' => $source->priority_id, 'status_id' => $source->status_id, 'parent_id' => $source->id, 'subject' => 'Child']);

    $copy = copyOptionsSave($project, $user, $source);

    expect($copy->children()->pluck('subject')->all())->toBe(['Child'])
        ->and($copy->watchers()->pluck('user_id')->all())->toContain($watcher->id);
});

test('unticked subtask and watcher boxes copy neither', function () {
    ['project' => $project, 'user' => $user, 'source' => $source, 'tracker' => $tracker] = copyOptionsSetup();
    $watcher = User::factory()->create();
    $source->watchers()->create(['user_id' => $watcher->id]);
    Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'priority_id' => $source->priority_id, 'status_id' => $source->status_id, 'parent_id' => $source->id]);

    $copy = copyOptionsSave($project, $user, $source, ['copySubtasks' => false, 'copyWatchers' => false]);

    expect($copy->children()->count())->toBe(0)->and($copy->watchers()->pluck('user_id')->all())->not->toContain($watcher->id);
});

test('without add_issue_watchers the watchers are not copied even if the box is forced on', function () {
    ['project' => $project, 'user' => $user, 'source' => $source] = copyOptionsSetup(['view_issues', 'add_issues']);
    $watcher = User::factory()->create();
    $source->watchers()->create(['user_id' => $watcher->id]);

    $copy = copyOptionsSave($project, $user, $source, ['copyWatchers' => true]);

    expect($copy->watchers()->pluck('user_id')->all())->not->toContain($watcher->id);
});

test('a plain new issue shows no copy options and copies nothing', function () {
    ['project' => $project, 'user' => $user] = copyOptionsSetup();

    Livewire::actingAs($user)->test('issues.form', ['project' => $project])->assertDontSee('data-copy-options', false);
});

test('the bulk copy form respects the link and attachment settings', function () {
    ['project' => $project, 'user' => $user, 'source' => $source, 'tracker' => $tracker] = copyOptionsSetup(['view_issues', 'add_issues', 'copy_issues']);
    $target = Project::factory()->create();
    $target->trackers()->attach($tracker);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]));

    Setting::set('link_copied_issue', 'no');
    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [$source->id])->set('bulkCopyToProjectId', $target->id)->set('bulkCopyToTrackerId', $tracker->id)->call('applyBulkCopy');
    expect($source->relationsFrom()->count())->toBe(0);

    Setting::set('link_copied_issue', 'ask');
    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [$source->id])->set('bulkCopyToProjectId', $target->id)->set('bulkCopyToTrackerId', $tracker->id)->set('bulkCopyLink', false)->call('applyBulkCopy');
    expect($source->relationsFrom()->count())->toBe(0);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [$source->id])->set('bulkCopyToProjectId', $target->id)->set('bulkCopyToTrackerId', $tracker->id)->call('applyBulkCopy');
    expect($source->relationsFrom()->count())->toBe(1);
});

test('the settings page saves both copy options and rejects unknown values', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('link_copied_issue', 'no')->set('copy_attachments_on_issue_copy', 'yes')->call('save')->assertHasNoErrors();
    expect(Setting::get('link_copied_issue'))->toBe('no')->and(Setting::get('copy_attachments_on_issue_copy'))->toBe('yes');

    Livewire::actingAs($admin)->test('settings.index')->set('link_copied_issue', 'maybe')->call('save')->assertHasErrors(['link_copied_issue']);
});
