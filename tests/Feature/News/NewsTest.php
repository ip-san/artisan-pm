<?php

use App\Models\Member;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function newsMember(Project $project, array $permissions = ['view_news']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with view_news can see the news list and an item', function () {
    $project = Project::factory()->create();
    $user = newsMember($project);
    $news = News::factory()->for($project)->create();

    Livewire::actingAs($user)->test('news.index', ['project' => $project])->assertOk();
    Livewire::actingAs($user)->test('news.show', ['project' => $project, 'news' => $news])->assertOk();
});

test('only a member with manage_news can create, edit, or delete news', function () {
    $project = Project::factory()->create();
    $member = newsMember($project);
    $manager = newsMember($project, ['view_news', 'manage_news']);

    Livewire::actingAs($member)->test('news.form', ['project' => $project])->assertForbidden();

    Livewire::actingAs($manager)
        ->test('news.form', ['project' => $project])
        ->set('title', 'Release 1.0')
        ->set('description', 'It shipped.')
        ->call('save');

    $news = News::where('title', 'Release 1.0')->firstOrFail();
    expect($news->author_id)->toBe($manager->id);

    Livewire::actingAs($member)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->call('delete')
        ->assertForbidden();

    Livewire::actingAs($manager)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->call('delete');

    expect(News::find($news->id))->toBeNull();
});

test('any logged-in member with comment_news can comment, but only manage_news can delete a comment', function () {
    $project = Project::factory()->create();
    $commenter = newsMember($project, ['view_news', 'comment_news']);
    $manager = newsMember($project, ['view_news', 'manage_news']);
    $news = News::factory()->for($project)->create();

    Livewire::actingAs($commenter)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->set('commentContent', 'Nice work!')
        ->call('addComment');

    $comment = NewsComment::where('news_id', $news->id)->firstOrFail();
    expect($comment->author_id)->toBe($commenter->id);

    Livewire::actingAs($commenter)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->call('deleteComment', $comment->id)
        ->assertForbidden();

    Livewire::actingAs($manager)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->call('deleteComment', $comment->id);

    expect(NewsComment::find($comment->id))->toBeNull();
});

test('a member without comment_news cannot comment', function () {
    $project = Project::factory()->create();
    $user = newsMember($project, ['view_news']);
    $news = News::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->set('commentContent', 'Hi')
        ->call('addComment')
        ->assertForbidden();
});

test('a member with view_news can watch and unwatch a news item', function () {
    $project = Project::factory()->create();
    $user = newsMember($project);
    $news = News::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->call('toggleWatch');

    expect($news->fresh()->isWatchedBy($user))->toBeTrue();

    Livewire::actingAs($user)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->call('toggleWatch');

    expect($news->fresh()->isWatchedBy($user))->toBeFalse();
});

test('creating a news item auto-watches its author', function () {
    $project = Project::factory()->create();
    $user = newsMember($project, ['view_news', 'manage_news']);

    Livewire::actingAs($user)
        ->test('news.form', ['project' => $project])
        ->set('title', 'Release notes')
        ->set('description', 'Details here')
        ->call('save')
        ->assertRedirect();

    $news = News::where('title', 'Release notes')->firstOrFail();

    expect($news->isWatchedBy($user))->toBeTrue();
});
