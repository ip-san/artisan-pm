<?php

use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\User;

test('a member cannot be saved without a user or a group', function () {
    $project = Project::factory()->create();

    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => null]);
})->throws(LogicException::class);

test('a member cannot be saved with both a user and a group', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $group = Group::factory()->create();

    Member::factory()->for($project)->create(['user_id' => $user->id, 'group_id' => $group->id]);
})->throws(LogicException::class);

test('a member can be saved with only a user', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $member = Member::factory()->for($project)->for($user)->create();

    expect($member->isForGroup())->toBeFalse();
});

test('a member can be saved with only a group', function () {
    $project = Project::factory()->create();
    $group = Group::factory()->create();

    $member = Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id]);

    expect($member->isForGroup())->toBeTrue();
});
