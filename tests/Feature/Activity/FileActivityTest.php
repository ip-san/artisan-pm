<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use App\Support\Activity\OffByDefault;
use App\Support\Attachments\AttachmentUploader;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function fileActivityMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

beforeEach(fn () => Storage::fake('local'));

test('a file added to the project or one of its versions shows up as a file entry with its uploader', function () {
    $project = Project::factory()->create();
    $viewer = fileActivityMember($project, ['view_project', 'view_files']);
    $uploader = User::factory()->create(['name' => 'Ulla Uploader']);
    $version = Version::factory()->for($project)->create();
    $project->addMediaFromString('a')->usingFileName('project-file.zip')->withCustomProperties([AttachmentUploader::PROPERTY => $uploader->id])->toMediaCollection('files');
    $version->addMediaFromString('b')->usingFileName('release.tar.gz')->toMediaCollection('files');

    $entries = Livewire::actingAs($viewer)->test('activity.index', ['project' => $project])->get('entries');
    $files = $entries->where('type', 'file')->keyBy('title');

    expect($files->keys()->all())->toEqualCanonicalizing(['project-file.zip', 'release.tar.gz'])
        ->and($files['project-file.zip']->authorName)->toBe('Ulla Uploader')
        ->and($files['release.tar.gz']->authorName)->toBeNull();
});

test('files need view_files, stay inside the project and honour the date range', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $without = fileActivityMember($project, ['view_project']);
    $with = fileActivityMember($project, ['view_project', 'view_files']);
    $project->addMediaFromString('a')->usingFileName('mine.zip')->toMediaCollection('files');
    $other->addMediaFromString('b')->usingFileName('theirs.zip')->toMediaCollection('files');
    $old = $project->addMediaFromString('c')->usingFileName('old.zip')->toMediaCollection('files');
    $old->forceFill(['created_at' => now()->subDays(60)])->save();

    expect(Livewire::actingAs($without)->test('activity.index', ['project' => $project])->get('entries')->where('type', 'file'))->toHaveCount(0)
        ->and(Livewire::actingAs($with)->test('activity.index', ['project' => $project])->get('entries')->where('type', 'file')->pluck('title')->all())->toBe(['mine.zip']);
});

test('wiki edits, messages and time entries start unchecked while files start checked', function () {
    $project = Project::factory()->create();
    $viewer = fileActivityMember($project, ['view_project', 'view_files', 'view_wiki_pages']);

    $active = Livewire::actingAs($viewer)->test('activity.index', ['project' => $project])->get('activeTypes');
    expect($active)->toContain('file', 'issue', 'news')->not->toContain('wiki-edit', 'message', 'time-entry');

    $global = Livewire::actingAs($viewer)->test('activity.global-index')->get('activeTypes');
    expect($global)->toContain('file')->not->toContain('wiki-edit');
});

test('the off-by-default providers are marked with the interface', function () {
    $providers = app(App\Support\Activity\ActivityProviderRegistry::class)->all();
    $off = $providers->filter(fn ($provider) => $provider instanceof OffByDefault)->map->type()->sort()->values()->all();

    expect($off)->toBe(['message', 'time-entry', 'wiki-edit']);
});
