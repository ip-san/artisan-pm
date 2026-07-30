<?php

use App\Models\Project;
use App\Models\Repository;
use Illuminate\Database\QueryException;

test('the same identifier can be used in two different projects', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    Repository::factory()->for($projectA)->create(['identifier' => 'main']);
    $repositoryB = Repository::factory()->for($projectB)->create(['identifier' => 'main']);

    expect($repositoryB->fresh()->identifier)->toBe('main');
});

test('a duplicate identifier within one project is rejected', function () {
    $project = Project::factory()->create();
    Repository::factory()->for($project)->create(['identifier' => 'main']);

    expect(fn () => Repository::factory()->for($project)->create(['identifier' => 'main']))
        ->toThrow(QueryException::class);
});

test('multiple identifier-less repositories can coexist within the same project', function () {
    // Every repository created before slice 2b's form exists has a null
    // identifier — the composite unique index must not treat that as a
    // collision (Postgres treats NULL as distinct in a unique index).
    $project = Project::factory()->create();

    $first = Repository::factory()->for($project)->create();
    $second = Repository::factory()->for($project)->create();

    expect($first->fresh()->identifier)->toBeNull()
        ->and($second->fresh()->identifier)->toBeNull();
});

test('identifierParam falls back to the numeric id when no identifier is set', function () {
    $repository = Repository::factory()->create();

    expect($repository->identifierParam())->toBe((string) $repository->id);
});

test('identifierParam returns the identifier when one is set', function () {
    $repository = Repository::factory()->create(['identifier' => 'main']);

    expect($repository->identifierParam())->toBe('main');
});

test('whereIdentifierParam resolves a purely-numeric param as an id lookup', function () {
    $repository = Repository::factory()->create(['identifier' => null]);
    $decoy = Repository::factory()->create(['identifier' => (string) $repository->id]);

    $found = Repository::query()->whereIdentifierParam((string) $repository->id)->first();

    expect($found->is($repository))->toBeTrue()
        ->and($found->is($decoy))->toBeFalse();
});

test('whereIdentifierParam resolves a non-numeric param as an identifier lookup', function () {
    $repository = Repository::factory()->create(['identifier' => 'main']);

    $found = Repository::query()->whereIdentifierParam('main')->first();

    expect($found->is($repository))->toBeTrue();
});

test('an identifier cannot be changed once set on a persisted repository', function () {
    $repository = Repository::factory()->create(['identifier' => 'main']);

    $repository->update(['identifier' => 'renamed']);

    expect($repository->fresh()->identifier)->toBe('main');
});

test('an identifier can still be set for the first time on a repository that started blank', function () {
    $repository = Repository::factory()->create(['identifier' => null]);

    $repository->update(['identifier' => 'now-set']);

    expect($repository->fresh()->identifier)->toBe('now-set');
});

test('an identifier can still be set for the first time on a repository that started as an empty string', function () {
    // A Livewire text input submits '' for an untouched field, not null —
    // slice 2b's form will produce this, not the null a factory-created
    // row defaults to. Both must count as "not yet set".
    $repository = Repository::factory()->create(['identifier' => '']);

    $repository->update(['identifier' => 'now-set']);

    expect($repository->fresh()->identifier)->toBe('now-set');
});
test('Project::resolveRepository returns the default repository when no identifier is given', function () {
    $project = Project::factory()->create();
    $other = Repository::factory()->for($project)->create();
    $default = Repository::factory()->for($project)->create(['is_default' => true]);

    expect($project->resolveRepository(null)->is($default))->toBeTrue();
});

test('Project::resolveRepository falls back to the first repository when no default is set', function () {
    // Unreachable via any real write path today (Repository's own hooks
    // always force a default onto a project's first repository), but
    // Redmine's find_project_repository has this fallback and it's cheap
    // to keep faithful in case that invariant ever gets a bypass added.
    $project = Project::factory()->create();
    $repository = Repository::factory()->for($project)->create();
    Repository::query()->whereKey($repository->id)->update(['is_default' => false]);

    expect($project->resolveRepository(null)->is($repository))->toBeTrue();
});

test('Project::resolveRepository resolves an explicit identifier to the matching repository, not the default', function () {
    $project = Project::factory()->create();
    $default = Repository::factory()->for($project)->create(['is_default' => true]);
    $named = Repository::factory()->for($project)->create(['identifier' => 'secondary']);

    expect($project->resolveRepository('secondary')->is($named))->toBeTrue()
        ->and($project->resolveRepository('secondary')->is($default))->toBeFalse();
});

test('Project::resolveRepository returns null for a project with no repositories at all', function () {
    $project = Project::factory()->create();

    expect($project->resolveRepository(null))->toBeNull();
});
