<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function actingMemberFor(Project $project): User
{
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('thumbnails are shown on the issue page by default', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $issue->addMedia(UploadedFile::fake()->image('photo.jpg', 400, 300))->toMediaCollection('attachments');

    $user = actingMemberFor($project);

    $this->actingAs($user)
        ->get(route('issues.show', [$project, $issue]))
        ->assertOk()
        ->assertSee('/thumb', false);
});

test('disabling thumbnails_enabled hides thumbnails on the issue page', function () {
    Storage::fake('local');
    Setting::set('thumbnails_enabled', false);

    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $issue->addMedia(UploadedFile::fake()->image('photo.jpg', 400, 300))->toMediaCollection('attachments');

    $user = actingMemberFor($project);

    $this->actingAs($user)
        ->get(route('issues.show', [$project, $issue]))
        ->assertOk()
        ->assertDontSee('/thumb', false);
});

test('an admin can toggle thumbnails_enabled from the settings screen', function () {
    $admin = User::factory()->admin()->create();

    expect(Setting::get('thumbnails_enabled', true))->toBeTrue();

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('thumbnails_enabled', false)
        ->call('save');

    expect(Setting::get('thumbnails_enabled', true))->toBeFalse();
});
