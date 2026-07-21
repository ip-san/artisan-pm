<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function fileSortingMember(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_files']])
    );

    return $user;
}

test('files default to ascending filename order', function () {
    $project = Project::factory()->create();
    $user = fileSortingMember($project);
    $project->addMedia(UploadedFile::fake()->create('zebra.txt', 10))->toMediaCollection('files');
    $project->addMedia(UploadedFile::fake()->create('apple.txt', 10))->toMediaCollection('files');

    $names = Livewire::actingAs($user)
        ->test('files.index', ['project' => $project])
        ->instance()
        ->sortedFiles($project->files())
        ->pluck('file_name');

    expect($names->all())->toBe(['apple.txt', 'zebra.txt']);
});

test('clicking the same sort field twice reverses the direction', function () {
    $project = Project::factory()->create();
    $user = fileSortingMember($project);

    $component = Livewire::actingAs($user)->test('files.index', ['project' => $project]);

    $component->call('sortFiles', 'filename');
    expect($component->get('sortBy'))->toBe('filename')->and($component->get('sortDirection'))->toBe('desc');

    $component->call('sortFiles', 'filename');
    expect($component->get('sortDirection'))->toBe('asc');
});

test('switching to a different field resets to ascending', function () {
    $project = Project::factory()->create();
    $user = fileSortingMember($project);

    $component = Livewire::actingAs($user)->test('files.index', ['project' => $project])
        ->call('sortFiles', 'filename')
        ->call('sortFiles', 'size');

    expect($component->get('sortBy'))->toBe('size')->and($component->get('sortDirection'))->toBe('asc');
});

test('files can be sorted by size', function () {
    $project = Project::factory()->create();
    $user = fileSortingMember($project);
    // UploadedFile::fake()->create() produces a sparse file whose declared
    // KB size doesn't survive Media Library's copy in this test environment
    // (it always lands as 0 bytes), so the size difference is forced
    // directly on the persisted row rather than relying on upload size.
    $big = $project->addMedia(UploadedFile::fake()->create('big.txt', 500))->toMediaCollection('files');
    $small = $project->addMedia(UploadedFile::fake()->create('small.txt', 10))->toMediaCollection('files');
    $big->forceFill(['size' => 512000])->save();
    $small->forceFill(['size' => 10240])->save();

    $component = Livewire::actingAs($user)->test('files.index', ['project' => $project])->call('sortFiles', 'size');

    $names = $component->instance()->sortedFiles($project->files())->pluck('file_name');

    expect($names->all())->toBe(['small.txt', 'big.txt']);
});

test('files can be sorted by download count', function () {
    $project = Project::factory()->create();
    $user = fileSortingMember($project);
    $mostDownloaded = $project->addMedia(UploadedFile::fake()->create('popular.txt', 10))->toMediaCollection('files');
    $leastDownloaded = $project->addMedia(UploadedFile::fake()->create('unpopular.txt', 10))->toMediaCollection('files');
    $mostDownloaded->setCustomProperty('download_count', 5)->save();
    $leastDownloaded->setCustomProperty('download_count', 1)->save();

    $component = Livewire::actingAs($user)->test('files.index', ['project' => $project])->call('sortFiles', 'downloads');

    $names = $component->instance()->sortedFiles($project->files())->pluck('file_name');

    expect($names->all())->toBe(['unpopular.txt', 'popular.txt']);
});
