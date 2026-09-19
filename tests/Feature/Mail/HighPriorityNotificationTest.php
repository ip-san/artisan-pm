<?php

use App\Enums\EnumerationType;
use App\Enums\MailNotificationOption;
use App\Enums\UserStatus;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Notifications\IssueNotification;
use App\Services\IssueService;
use App\Support\Preferences\UserPreferences;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * @return array{project: Project, normal: Enumeration, high: Enumeration, low: Enumeration}
 */
function priorityProject(): array
{
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());

    return [
        'project' => $project,
        'low' => Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value, 'name' => 'Low', 'position' => 1, 'is_default' => false]),
        'normal' => Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value, 'name' => 'Normal', 'position' => 2, 'is_default' => true]),
        'high' => Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value, 'name' => 'High', 'position' => 3, 'is_default' => false]),
    ];
}

function priorityMember(Project $project, MailNotificationOption $option, bool $optIn, ?callable $tweak = null): User
{
    $user = User::factory()->create(['mail_notification' => $option]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]));

    if ($optIn) {
        UserPreferences::save($user, ['notify_about_high_priority_issues' => true]);
    }

    $tweak?->__invoke($user);

    return $user->fresh();
}

function priorityIssue(Project $project, Enumeration $priority, User $author): App\Models\Issue
{
    return app(IssueService::class)->create([
        'project_id' => $project->id,
        'tracker_id' => $project->trackers->first()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => $priority->id,
        'subject' => 'Priority test',
    ], $author);
}

test('an opted-in member is told about a higher-priority issue whatever their notification setting says', function () {
    Notification::fake();
    ['project' => $project, 'high' => $high] = priorityProject();
    $author = priorityMember($project, MailNotificationOption::OnlyMyEvents, false);
    $optedIn = priorityMember($project, MailNotificationOption::None, true);
    $notOptedIn = priorityMember($project, MailNotificationOption::OnlyMyEvents, false);

    priorityIssue($project, $high, $author);

    Notification::assertSentTo($optedIn, IssueNotification::class);
    Notification::assertNotSentTo($notOptedIn, IssueNotification::class);
});

test('a default or lower priority does not trigger it', function () {
    Notification::fake();
    ['project' => $project, 'normal' => $normal, 'low' => $low] = priorityProject();
    $author = priorityMember($project, MailNotificationOption::OnlyMyEvents, false);
    $optedIn = priorityMember($project, MailNotificationOption::None, true);

    priorityIssue($project, $normal, $author);
    priorityIssue($project, $low, $author);

    Notification::assertNotSentTo($optedIn, IssueNotification::class);
});

test('the opted-in member gets one mail even when their normal setting would also send it', function () {
    Notification::fake();
    ['project' => $project, 'high' => $high] = priorityProject();
    $author = priorityMember($project, MailNotificationOption::OnlyMyEvents, false);
    $optedIn = priorityMember($project, MailNotificationOption::All, true);

    priorityIssue($project, $high, $author);

    Notification::assertSentToTimes($optedIn, IssueNotification::class, 1);
});

test('non-members, locked users and people who cannot see the issue are left out', function () {
    Notification::fake();
    ['project' => $project, 'high' => $high] = priorityProject();
    $author = priorityMember($project, MailNotificationOption::OnlyMyEvents, false);
    $outsider = User::factory()->create();
    UserPreferences::save($outsider, ['notify_about_high_priority_issues' => true]);
    $locked = priorityMember($project, MailNotificationOption::None, true, fn (User $user) => $user->forceFill(['status' => UserStatus::Locked])->save());
    $blind = User::factory()->create(['mail_notification' => MailNotificationOption::None]);
    Member::factory()->for($project)->for($blind)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project']]));
    UserPreferences::save($blind, ['notify_about_high_priority_issues' => true]);

    priorityIssue($project, $high, $author);

    Notification::assertNotSentTo($outsider, IssueNotification::class);
    Notification::assertNotSentTo($locked, IssueNotification::class);
    Notification::assertNotSentTo($blind, IssueNotification::class);
});

test('the author who asked not to be told about their own changes is not mailed', function () {
    Notification::fake();
    ['project' => $project, 'high' => $high] = priorityProject();
    $author = priorityMember($project, MailNotificationOption::None, true, fn (User $user) => $user->forceFill(['no_self_notified' => true])->save());

    priorityIssue($project, $high, $author);

    Notification::assertNotSentTo($author, IssueNotification::class);
});

test('without a default priority nothing counts as high', function () {
    Notification::fake();
    ['project' => $project, 'high' => $high, 'normal' => $normal] = priorityProject();
    $normal->update(['is_default' => false]);
    $author = priorityMember($project, MailNotificationOption::OnlyMyEvents, false);
    $optedIn = priorityMember($project, MailNotificationOption::None, true);

    priorityIssue($project, $high, $author);

    Notification::assertNotSentTo($optedIn, IssueNotification::class);
});

test('the profile stores the option', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('profile.index')->set('notify_about_high_priority_issues', true)->call('savePreferences')->assertHasNoErrors();

    expect($user->fresh()->preference('notify_about_high_priority_issues'))->toBeTrue();
});
