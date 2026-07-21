<?php

use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;

test('a custom field restricted to specific roles is hidden from a role without access', function () {
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();

    $visibleTo = Role::factory()->create(['permissions' => ['view_issues']]);
    $hiddenFrom = Role::factory()->create(['permissions' => ['view_issues']]);

    $field = CustomField::factory()->create(['name' => 'Internal notes']);
    $field->trackers()->attach($tracker);
    $field->roles()->attach($visibleTo);

    $allowedUser = User::factory()->create();
    $allowedMember = Member::factory()->for($project)->for($allowedUser)->create();
    $allowedMember->roles()->attach($visibleTo);

    $deniedUser = User::factory()->create();
    $deniedMember = Member::factory()->for($project)->for($deniedUser)->create();
    $deniedMember->roles()->attach($hiddenFrom);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    $this->actingAs($allowedUser);
    expect($issue->relevantCustomFields()->pluck('id'))->toContain($field->id);

    $this->actingAs($deniedUser);
    expect($issue->relevantCustomFields()->pluck('id'))->not->toContain($field->id);
});

test('a custom field with no role restriction is visible to every role', function () {
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();

    $field = CustomField::factory()->create(['name' => 'Everyone can see this']);
    $field->trackers()->attach($tracker);

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    $this->actingAs($user);
    expect($issue->relevantCustomFields()->pluck('id'))->toContain($field->id);
});

test('an admin sees role-restricted custom fields regardless of their own role', function () {
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();

    $onlyRole = Role::factory()->create();
    $field = CustomField::factory()->create();
    $field->trackers()->attach($tracker);
    $field->roles()->attach($onlyRole);

    $admin = User::factory()->admin()->create();
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    $this->actingAs($admin);
    expect($issue->relevantCustomFields()->pluck('id'))->toContain($field->id);
});

test('a role-restricted field is hidden from an unauthenticated context', function () {
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();

    $role = Role::factory()->create();
    $field = CustomField::factory()->create();
    $field->trackers()->attach($tracker);
    $field->roles()->attach($role);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    expect($issue->relevantCustomFields()->pluck('id'))->not->toContain($field->id);
});
