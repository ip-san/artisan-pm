<?php

use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Laravel\Passport\Passport;

function apiNewsMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('unauthenticated requests are rejected', function () {
    $project = Project::factory()->create();
    $this->getJson("/api/v1/projects/{$project->id}/news")->assertUnauthorized();
});

test('a member with view_news can list a project\'s news', function () {
    $project = Project::factory()->create();
    $user = apiNewsMember($project, ['view_news']);
    $news = News::factory()->for($project)->create(['title' => 'Launch day']);

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/projects/{$project->id}/news");

    $response->assertOk()->assertJsonPath('data.0.id', $news->id)->assertJsonPath('data.0.title', 'Launch day');
});

test('a member without view_news cannot list news', function () {
    $project = Project::factory()->create();
    $user = apiNewsMember($project, ['view_issues']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/projects/{$project->id}/news")->assertForbidden();
});

test('a non-member cannot list news in a private project', function () {
    $project = Project::factory()->private()->create();
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/projects/{$project->id}/news")->assertForbidden();
});

test('a member with view_news can show a single news item including its comment count', function () {
    $project = Project::factory()->create();
    $user = apiNewsMember($project, ['view_news']);
    $news = News::factory()->for($project)->create();
    $news->comments()->create(['author_id' => $user->id, 'content' => 'Nice!']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/news/{$news->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $news->id)
        ->assertJsonPath('data.comments_count', 1);
});

test('a non-member cannot show news in a private project', function () {
    $project = Project::factory()->private()->create();
    $news = News::factory()->for($project)->create();
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/news/{$news->id}")->assertForbidden();
});

test('a member with manage_news can create a news item', function () {
    $project = Project::factory()->create();
    $user = apiNewsMember($project, ['manage_news']);

    Passport::actingAs($user);

    $response = $this->postJson("/api/v1/projects/{$project->id}/news", [
        'title' => 'New release',
        'summary' => 'Version 2.0 is out',
        'description' => 'Full changelog goes here.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'New release')
        ->assertJsonPath('data.author_id', $user->id);

    expect(News::where('title', 'New release')->first()->project_id)->toBe($project->id);
});

test('a member without manage_news cannot create a news item', function () {
    $project = Project::factory()->create();
    $user = apiNewsMember($project, ['view_news']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/news", [
        'title' => 'New release',
        'description' => 'Full changelog goes here.',
    ])->assertForbidden();
});

test('a non-member cannot create a news item in a private project', function () {
    $project = Project::factory()->private()->create();
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/news", [
        'title' => 'New release',
        'description' => 'Full changelog goes here.',
    ])->assertForbidden();
});

test('creating a news item without a title is rejected', function () {
    $project = Project::factory()->create();
    $user = apiNewsMember($project, ['manage_news']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/news", [
        'description' => 'Full changelog goes here.',
    ])->assertUnprocessable()->assertJsonValidationErrors(['title']);
});

test('a member with manage_news can update a news item', function () {
    $project = Project::factory()->create();
    $user = apiNewsMember($project, ['manage_news']);
    $news = News::factory()->for($project)->create(['title' => 'Old title']);

    Passport::actingAs($user);

    $this->putJson("/api/v1/news/{$news->id}", ['title' => 'Updated title'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Updated title');
});

test('a member without manage_news cannot update a news item', function () {
    $project = Project::factory()->create();
    $user = apiNewsMember($project, ['view_news']);
    $news = News::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->putJson("/api/v1/news/{$news->id}", ['title' => 'Hacked'])->assertForbidden();
});

test('a non-member cannot update a news item in a private project', function () {
    $project = Project::factory()->private()->create();
    $news = News::factory()->for($project)->create();
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->putJson("/api/v1/news/{$news->id}", ['title' => 'Hacked'])->assertForbidden();
});

test('a member with manage_news can delete a news item', function () {
    $project = Project::factory()->create();
    $user = apiNewsMember($project, ['manage_news']);
    $news = News::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/news/{$news->id}")->assertNoContent();

    expect(News::find($news->id))->toBeNull();
});

test('a member without manage_news cannot delete a news item', function () {
    $project = Project::factory()->create();
    $user = apiNewsMember($project, ['view_news']);
    $news = News::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/news/{$news->id}")->assertForbidden();

    expect(News::find($news->id))->not->toBeNull();
});

test('a non-member cannot delete a news item in a private project', function () {
    $project = Project::factory()->private()->create();
    $news = News::factory()->for($project)->create();
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/news/{$news->id}")->assertForbidden();

    expect(News::find($news->id))->not->toBeNull();
});

test('the global index lists news of every project the caller may view news in, newest first', function () {
    $visible = Project::factory()->create();
    $noNewsRole = Project::factory()->create();
    $private = Project::factory()->private()->create();
    $public = Project::factory()->create();
    $user = apiNewsMember($visible, ['view_news']);
    Member::factory()->for($noNewsRole)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    $older = News::factory()->for($visible)->create(['created_at' => now()->subDay()]);
    $newer = News::factory()->for($visible)->create(['created_at' => now()]);
    $hiddenByRole = News::factory()->for($noNewsRole)->create();
    $hiddenPrivate = News::factory()->for($private)->create();
    $publicItem = News::factory()->for($public)->create(['created_at' => now()->subDays(2)]);

    Passport::actingAs($user);

    $ids = collect($this->getJson('/api/v1/news')->assertOk()->json('data'))->pluck('id');

    expect($ids->take(2)->all())->toBe([$newer->id, $older->id])
        ->and($ids)->not->toContain($hiddenByRole->id)
        ->and($ids)->not->toContain($hiddenPrivate->id)
        ->and($publicItem->id)->toBeInt();
});

test('the global index needs authentication and project_id narrows it without widening access', function () {
    $this->getJson('/api/v1/news')->assertUnauthorized();

    $mine = Project::factory()->create();
    $secret = Project::factory()->private()->create();
    $user = apiNewsMember($mine, ['view_news']);
    $inMine = News::factory()->for($mine)->create();
    $inSecret = News::factory()->for($secret)->create();

    Passport::actingAs($user);

    expect(collect($this->getJson("/api/v1/news?project_id={$mine->id}")->json('data'))->pluck('id')->all())->toBe([$inMine->id])
        ->and(collect($this->getJson("/api/v1/news?project_id={$secret->id}")->json('data'))->pluck('id')->all())->toBe([])
        ->and($inSecret->id)->toBeInt();
});

test('the global index reports comment counts without per-row queries', function () {
    $project = Project::factory()->create();
    $user = apiNewsMember($project, ['view_news']);
    News::factory()->for($project)->count(5)->create();

    Passport::actingAs($user);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->getJson('/api/v1/news')->assertOk()->assertJsonStructure(['data' => [['id', 'comments_count']]]);

    expect($queries)->toBeLessThan(15);
});
