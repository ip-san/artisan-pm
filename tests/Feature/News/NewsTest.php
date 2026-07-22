<?php

use App\Models\Member;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

test('a member with view_news can fetch the project news atom feed, newest first', function () {
    $project = Project::factory()->create();
    $user = newsMember($project);
    News::factory()->for($project)->create(['title' => 'Older announcement', 'created_at' => now()->subDay()]);
    News::factory()->for($project)->create(['title' => 'Newer announcement', 'created_at' => now()]);

    $response = $this->actingAs($user)->get(route('news.atom', $project));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/atom+xml');
    $response->assertSee('<feed', false);
    $response->assertSee('Older announcement', false);
    $response->assertSeeInOrder(['Newer announcement', 'Older announcement'], false);
});

test('a member without view_news cannot fetch the project news atom feed', function () {
    $project = Project::factory()->create();
    $user = newsMember($project, []);

    $this->actingAs($user)->get(route('news.atom', $project))->assertForbidden();
});

test('the global news index only shows items from projects the user can view_news in', function () {
    $visibleProject = Project::factory()->create();
    $hiddenProject = Project::factory()->create();
    $user = newsMember($visibleProject);
    News::factory()->for($visibleProject)->create(['title' => 'Visible announcement']);
    News::factory()->for($hiddenProject)->create(['title' => 'Hidden announcement']);

    Livewire::actingAs($user)
        ->test('news.global-index')
        ->assertSee('Visible announcement')
        ->assertDontSee('Hidden announcement');
});

test('the global news index paginates at 10 items per page, newest first', function () {
    $project = Project::factory()->create();
    $user = newsMember($project);

    for ($i = 1; $i <= 11; $i++) {
        News::factory()->for($project)->create(['title' => "Announcement {$i}", 'created_at' => now()->addSeconds($i)]);
    }

    $page1 = Livewire::actingAs($user)->test('news.global-index')->get('newsItems');
    expect($page1->pluck('title')->all())->toHaveCount(10)
        ->and($page1->first()->title)->toBe('Announcement 11');

    $page2 = Livewire::actingAs($user)->test('news.global-index')->call('gotoPage', 2)->get('newsItems');
    expect($page2->pluck('title')->all())->toBe(['Announcement 1']);
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

test('a manager can set an attachment description on a news item', function () {
    $project = Project::factory()->create();
    $manager = newsMember($project, ['view_news', 'manage_news']);
    $news = News::factory()->for($project)->create();
    $media = $news->addMedia(UploadedFile::fake()->create('slides.pdf', 200))->toMediaCollection('attachments');

    Livewire::actingAs($manager)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->set("attachmentDescriptions.{$media->id}", 'Presentation slides')
        ->call('updateAttachmentDescription', $media->id);

    expect($media->fresh()->getCustomProperty('description'))->toBe('Presentation slides');
});

test('a member without manage_news cannot set an attachment description', function () {
    $project = Project::factory()->create();
    $user = newsMember($project);
    $news = News::factory()->for($project)->create();
    $media = $news->addMedia(UploadedFile::fake()->create('slides.pdf', 200))->toMediaCollection('attachments');

    Livewire::actingAs($user)
        ->test('news.show', ['project' => $project, 'news' => $news])
        ->set("attachmentDescriptions.{$media->id}", 'sneaky')
        ->call('updateAttachmentDescription', $media->id)
        ->assertForbidden();

    expect($media->fresh()->getCustomProperty('description'))->toBeNull();
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
