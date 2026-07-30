<?php

use App\Models\Project;
use App\Models\Repository;
use Illuminate\Support\Facades\DB;

test('a project\'s only repository is automatically its default', function () {
    $project = Project::factory()->create();
    $repository = Repository::factory()->for($project)->create();

    expect($repository->fresh()->is_default)->toBeTrue();
});

test('marking a new repository as default unsets the previous default for that project', function () {
    $project = Project::factory()->create();
    $first = Repository::factory()->for($project)->create();

    expect($first->fresh()->is_default)->toBeTrue();

    $second = Repository::factory()->for($project)->create(['is_default' => true]);

    expect($second->fresh()->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse();
});

test('a second repository created without is_default set explicitly does not become the default', function () {
    $project = Project::factory()->create();
    $first = Repository::factory()->for($project)->create();
    $second = Repository::factory()->for($project)->create();

    expect($first->fresh()->is_default)->toBeTrue()
        ->and($second->fresh()->is_default)->toBeFalse();
});

test('Project::repository() resolves only the default repository', function () {
    $project = Project::factory()->create();
    $first = Repository::factory()->for($project)->create();
    $second = Repository::factory()->for($project)->create(['is_default' => true]);

    expect($project->repository()->first()->is($second))->toBeTrue()
        ->and($project->repositories()->count())->toBe(2);
});

test('updating an unrelated attribute does not re-run the default-repository sweep', function () {
    // RepositorySyncService::sync() calls $repository->update() once per
    // synced changeset to advance last_synced_revision — a tight loop
    // where an extra query per iteration would matter. The saved hook
    // must only sweep when is_default itself changed.
    $project = Project::factory()->create();
    $repository = Repository::factory()->for($project)->create();

    DB::enableQueryLog();
    $repository->update(['last_synced_revision' => 'abc123']);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries)->each(fn ($query) => $query->not->toContain('is_default'));
});

test('is_default is not left unset for repositories that existed before the column was added', function () {
    // The 2026_07_30_010000 migration backfills every pre-existing row as
    // its project's default, since the column previously didn't exist and
    // the table's old unique(project_id) constraint meant every row was
    // implicitly "the" repository for its project. RefreshDatabase always
    // runs migrations against an empty table, so the normal test run never
    // exercises this backfill path — this test drives the migration's
    // up()/down() directly against a row inserted to simulate pre-existing
    // data, bypassing the model's own hooks entirely.
    $migration = require database_path('migrations/2026_07_30_010000_add_is_default_to_repositories_table.php');

    $migration->down();

    $project = Project::factory()->create();
    $id = DB::table('repositories')->insertGetId([
        'project_id' => $project->id,
        'type' => 'git',
        'path' => '/tmp/pre-existing-repo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('repositories')->where('id', $id)->value('is_default'))->toBeTrue();
});
