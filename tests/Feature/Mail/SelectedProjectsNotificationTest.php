<?php

use App\Enums\MailNotificationOption;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Notifications\IssueNotification;
use App\Services\IssueService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function selectedProject(): Project
{
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());

    return $project;
}

function selectedMember(Project $project, MailNotificationOption $option, bool $ticked = false): User
{
    $user = User::factory()->create(['mail_notification' => $option]);
    Member::factory()->for($project)->for($user)->create(['mail_notification' => $ticked])->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]));

    return $user;
}

function selectedIssue(Project $project, User $author, ?User $assignee = null): Issue
{
    return app(IssueService::class)->create([
        'project_id' => $project->id,
        'tracker_id' => $project->trackers->first()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Selected test',
        'assigned_to_id' => $assignee?->id,
    ], $author);
}

test('a user on the selected setting hears about every event in a project they ticked', function () {
    Notification::fake();
    $project = selectedProject();
    $author = selectedMember($project, MailNotificationOption::OnlyMyEvents);
    $ticked = selectedMember($project, MailNotificationOption::Selected, true);

    selectedIssue($project, $author);

    Notification::assertSentTo($ticked, IssueNotification::class);
});

test('in a project they did not tick they only hear about what involves them', function () {
    Notification::fake();
    $project = selectedProject();
    $author = selectedMember($project, MailNotificationOption::OnlyMyEvents);
    $unticked = selectedMember($project, MailNotificationOption::Selected, false);
    $assignee = selectedMember($project, MailNotificationOption::Selected, false);

    selectedIssue($project, $author, $assignee);

    Notification::assertNotSentTo($unticked, IssueNotification::class);
    Notification::assertSentTo($assignee, IssueNotification::class);
});

test('another user\'s ticked project does not leak to a user who has the all setting off', function () {
    Notification::fake();
    $project = selectedProject();
    $author = selectedMember($project, MailNotificationOption::OnlyMyEvents);
    $ticksButOnlyAssigned = selectedMember($project, MailNotificationOption::OnlyAssigned, true);

    selectedIssue($project, $author);

    Notification::assertNotSentTo($ticksButOnlyAssigned, IssueNotification::class);
});

test('the profile offers the selected setting only to someone with a project, and lists their projects', function () {
    $loner = User::factory()->create();
    Livewire::actingAs($loner)->test('profile.index')->assertDontSee('選択したプロジェクトのイベント');

    $project = Project::factory()->create(['name' => 'Alpha Project']);
    $member = User::factory()->create(['mail_notification' => MailNotificationOption::Selected]);
    Member::factory()->for($project)->for($member)->create();

    Livewire::actingAs($member)->test('profile.index')->assertSee('選択したプロジェクトのイベント')->assertSee('Alpha Project')->assertSee('data-notified-projects', false);
});

test('saving the profile stores the ticked projects and clears them when another setting is chosen', function () {
    $user = User::factory()->create();
    $alpha = Project::factory()->create();
    $beta = Project::factory()->create();
    Member::factory()->for($alpha)->for($user)->create();
    Member::factory()->for($beta)->for($user)->create();

    Livewire::actingAs($user)->test('profile.index')
        ->set('mail_notification', 'selected')->set('notified_project_ids', [(string) $alpha->id])
        ->call('updateProfile')->assertHasNoErrors();
    expect($user->fresh()->notifiedProjectIds())->toBe([$alpha->id])->and($user->fresh()->mail_notification)->toBe(MailNotificationOption::Selected);

    Livewire::actingAs($user->fresh())->test('profile.index')->assertSet('notified_project_ids', [(string) $alpha->id])
        ->set('mail_notification', 'all')->call('updateProfile');
    expect($user->fresh()->notifiedProjectIds())->toBe([]);
});

test('a project the user does not belong to cannot be ticked', function () {
    $user = User::factory()->create();
    Member::factory()->for(Project::factory()->create())->for($user)->create();
    $foreign = Project::factory()->create();

    Livewire::actingAs($user)->test('profile.index')
        ->set('mail_notification', 'selected')->set('notified_project_ids', [(string) $foreign->id])
        ->call('updateProfile')->assertHasErrors(['notified_project_ids.0']);
});

test('the selected setting is refused for a user with no membership at all', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('profile.index')->set('mail_notification', 'selected')->call('updateProfile')->assertHasErrors(['mail_notification']);
});
