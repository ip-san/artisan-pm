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
