<?php

use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

function groupMember(bool $groupRequires, array $userAttributes = []): User
{
    $user = User::factory()->create($userAttributes);
    $group = Group::factory()->create(['twofa_required' => $groupRequires]);
    $group->users()->attach($user);

    return $user;
}

test('under the optional tier a member of a twofa_required group must activate 2FA', function (string $tier) {
    Setting::set('twofa', $tier);
    $user = groupMember(true);

    expect($user->mustActivateTwoFactor())->toBeTrue();

    $this->actingAs($user)->get(route('projects.index'))->assertRedirect(route('profile.index'));
})->with(['optional' => '1', 'admins only' => '3']);

test('a member of a group that does not require 2FA is not forced', function () {
    Setting::set('twofa', '1');

    expect(groupMember(false)->mustActivateTwoFactor())->toBeFalse();
});

test('the group flag is ignored when 2FA is disabled site wide', function () {
    Setting::set('twofa', '0');

    expect(groupMember(true)->mustActivateTwoFactor())->toBeFalse();
});

test('a user who already has 2FA confirmed is not forced by the group', function () {
    Setting::set('twofa', '1');
    $user = groupMember(true, ['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()]);

    expect($user->mustActivateTwoFactor())->toBeFalse();
});

test('a user outside every group is unaffected', function () {
    Setting::set('twofa', '1');
    Group::factory()->create(['twofa_required' => true]);

    expect(User::factory()->create()->mustActivateTwoFactor())->toBeFalse();
});

test('the group form saves the flag only while the site setting is optional or admins only', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();

    Setting::set('twofa', '1');
    Livewire::actingAs($admin)->test('groups.form', ['group' => $group])
        ->assertSet('twofaRequired', false)
        ->set('twofaRequired', true)
        ->call('save')
        ->assertHasNoErrors();
    expect($group->fresh()->twofa_required)->toBeTrue();

    Setting::set('twofa', '0');
    Livewire::actingAs($admin)->test('groups.form', ['group' => $group->fresh()])
        ->set('twofaRequired', false)
        ->call('save')
        ->assertHasNoErrors();
    expect($group->fresh()->twofa_required)->toBeTrue();
});
