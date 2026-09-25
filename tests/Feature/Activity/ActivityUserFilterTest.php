<?php

use App\Enums\UserStatus;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/**
 * A15-09: Redmine's ActivitiesController#index user_id param, on both the
 * project and cross-project activity pages, and their Atom feeds.
 *
 * @param  array<int, string>  $permissions
 */
function activityUserFilterMember(Project $project, array $permissions = ['view_project', 'view_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('filtering the project activity page by user_id shows only that author\'s entries', function () {
    $project = Project::factory()->create();
    $viewer = activityUserFilterMember($project);
    $author = activityUserFilterMember($project);
    $other = activityUserFilterMember($project);

    $mine = Issue::factory()->for($project)->create(['subject' => 'Mine', 'author_id' => $author->id]);
    $theirs = Issue::factory()->for($project)->create(['subject' => 'Theirs', 'author_id' => $other->id]);

    $component = Livewire::actingAs($viewer)->test('activity.index', ['project' => $project])
        ->set('userId', $author->id);

    $titles = $component->get('entries')->pluck('title');

    expect($titles->contains(fn ($title) => str_contains($title, $mine->subject)))->toBeTrue()
        ->and($titles->contains(fn ($title) => str_contains($title, $theirs->subject)))->toBeFalse();
});

test('an unknown or inactive user_id 404s, matching Redmine\'s User.visible.active.find', function () {
    $project = Project::factory()->create();
    $viewer = activityUserFilterMember($project);
    $inactive = User::factory()->create(['status' => UserStatus::Locked]);

    Livewire::actingAs($viewer)->test('activity.index', ['project' => $project, 'userId' => 999999])->assertNotFound();
    Livewire::actingAs($viewer)->test('activity.index', ['project' => $project, 'userId' => $inactive->id])->assertNotFound();
});

test('the project activity Atom feed honors userId too', function () {
    $project = Project::factory()->create();
    $viewer = activityUserFilterMember($project);
    $author = activityUserFilterMember($project);
    $other = activityUserFilterMember($project);

    $mine = Issue::factory()->for($project)->create(['subject' => 'Mine-atom', 'author_id' => $author->id]);
    $theirs = Issue::factory()->for($project)->create(['subject' => 'Theirs-atom', 'author_id' => $other->id]);

    $response = $this->actingAs($viewer)->get(route('activity.atom', [$project, 'userId' => $author->id]))->assertOk();

    $response->assertSee($mine->subject, false)->assertDontSee($theirs->subject, false);
});

test('filtering the cross-project activity page by user_id shows only that author\'s entries', function () {
    $project = Project::factory()->create();
    $viewer = activityUserFilterMember($project);
    $author = activityUserFilterMember($project);
    $other = activityUserFilterMember($project);

    $mine = Issue::factory()->for($project)->create(['subject' => 'Global mine', 'author_id' => $author->id]);
    $theirs = Issue::factory()->for($project)->create(['subject' => 'Global theirs', 'author_id' => $other->id]);

    $component = Livewire::actingAs($viewer)->test('activity.global-index')
        ->set('userId', $author->id);

    $titles = $component->get('entries')->pluck('title');

    expect($titles->contains(fn ($title) => str_contains($title, $mine->subject)))->toBeTrue()
        ->and($titles->contains(fn ($title) => str_contains($title, $theirs->subject)))->toBeFalse();
});

test('the cross-project activity Atom feed honors userId too', function () {
    $project = Project::factory()->create();
    $viewer = activityUserFilterMember($project);
    $author = activityUserFilterMember($project);
    $other = activityUserFilterMember($project);

    $mine = Issue::factory()->for($project)->create(['subject' => 'Global-atom mine', 'author_id' => $author->id]);
    $theirs = Issue::factory()->for($project)->create(['subject' => 'Global-atom theirs', 'author_id' => $other->id]);

    $response = $this->actingAs($viewer)->get(route('activity.global-atom', ['userId' => $author->id]))->assertOk();

    $response->assertSee($mine->subject, false)->assertDontSee($theirs->subject, false);
});
