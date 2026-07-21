<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('uploading an image attachment generates a thumb conversion', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $media = $issue->addMedia(UploadedFile::fake()->image('photo.jpg', 400, 300))->toMediaCollection('attachments');

    expect($media->hasGeneratedConversion('thumb'))->toBeTrue();
});

test('uploading a non-image attachment does not generate a thumb conversion', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $media = $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    expect($media->hasGeneratedConversion('thumb'))->toBeFalse();
});

test('a member who can view the issue can fetch its attachment thumbnail', function () {
    Storage::fake('local');

    $project = Project::factory()->private()->create();
    $issue = Issue::factory()->for($project)->create();
    $media = $issue->addMedia(UploadedFile::fake()->image('photo.jpg', 400, 300))->toMediaCollection('attachments');

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $this->actingAs($user)
        ->get(route('attachments.thumb', $media))
        ->assertOk();
});

test('a non-member cannot fetch a thumbnail from a private project', function () {
    Storage::fake('local');

    $project = Project::factory()->private()->create();
    $issue = Issue::factory()->for($project)->create();
    $media = $issue->addMedia(UploadedFile::fake()->image('photo.jpg', 400, 300))->toMediaCollection('attachments');

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('attachments.thumb', $media))
        ->assertForbidden();
});

test('requesting a thumbnail for a non-image attachment 404s', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $media = $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $this->actingAs($user)
        ->get(route('attachments.thumb', $media))
        ->assertNotFound();
});
