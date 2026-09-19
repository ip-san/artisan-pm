<?php

use App\Models\Document;
use App\Models\Issue;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use App\Support\Attachments\AttachmentUploader;
use Illuminate\Http\UploadedFile;
use Laravel\Passport\Passport;
use Livewire\Livewire;

function uploaderMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('a file added by a signed-in user records that user as its uploader', function () {
    $user = User::factory()->create();
    $document = Document::factory()->create();

    $this->actingAs($user);
    $media = $document->addMediaFromString('content')->usingFileName('a.txt')->toMediaCollection('attachments');

    expect(AttachmentUploader::idOf($media))->toBe($user->id)
        ->and(AttachmentUploader::userOf($media)?->is($user))->toBeTrue();
});

test('an explicitly named uploader is never overwritten by the request user', function () {
    $requester = User::factory()->create();
    $named = User::factory()->create();
    $document = Document::factory()->create();

    $this->actingAs($requester);
    $media = $document->addMediaFromString('x')->usingFileName('a.txt')
        ->withCustomProperties([AttachmentUploader::PROPERTY => $named->id])
        ->toMediaCollection('attachments');

    expect(AttachmentUploader::idOf($media))->toBe($named->id);
});

test('a file created with no signed-in user stays anonymous instead of failing', function () {
    $media = Document::factory()->create()->addMediaFromString('x')->usingFileName('a.txt')->toMediaCollection('attachments');

    expect(AttachmentUploader::idOf($media))->toBeNull()
        ->and(AttachmentUploader::userOf($media))->toBeNull();
});

test('every web upload form records the uploader', function () {
    $project = Project::factory()->create();
    $user = uploaderMember($project, ['view_issues', 'add_issues', 'edit_issues', 'view_documents', 'add_documents', 'edit_documents', 'view_news', 'manage_news', 'view_wiki_pages', 'edit_wiki_pages']);
    $file = fn (string $name) => UploadedFile::fake()->create($name, 5);

    $document = Document::factory()->for($project)->create();
    Livewire::actingAs($user)->test('documents.form', ['project' => $project, 'document' => $document])
        ->set('newAttachments', [$file('doc.pdf')])
        ->call('save');

    $news = News::factory()->for($project)->create();
    Livewire::actingAs($user)->test('news.form', ['project' => $project, 'news' => $news])
        ->set('newAttachments', [$file('news.pdf')])
        ->call('save');

    $page = WikiPage::factory()->for($project)->create();
    Livewire::actingAs($user)->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->set('newAttachments', [$file('wiki.pdf')])
        ->call('save');

    foreach ([$document->fresh(), $news->fresh(), $page->fresh()] as $owner) {
        $media = $owner->getMedia('attachments')->first();

        expect($media)->not->toBeNull()
            ->and(AttachmentUploader::idOf($media))->toBe($user->id);
    }
});

test('the REST upload flow keeps the uploader through the move onto the issue', function () {
    $project = Project::factory()->create();
    $user = uploaderMember($project, ['view_issues', 'add_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => App\Models\Tracker::factory()->create()->id,
        'status_id' => App\Models\IssueStatus::factory()->create()->id,
        'priority_id' => App\Models\Enumeration::factory()->create()->id,
    ]);

    Passport::actingAs($user);
    $token = $this->call('POST', '/api/v1/uploads?filename=api.pdf', server: ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], content: 'binary-content')->assertCreated()->json('upload.token');

    $this->putJson("/api/v1/issues/{$issue->id}", ['uploads' => [['token' => $token, 'filename' => 'api.pdf']]])->assertOk();

    $media = $issue->fresh()->getMedia('attachments')->first();

    expect($media)->not->toBeNull()
        ->and(AttachmentUploader::idOf($media))->toBe($user->id);
});

test('documents can be grouped by the uploader of their latest attachment', function () {
    $project = Project::factory()->create();
    $viewer = uploaderMember($project, ['view_documents']);
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    $byAlice = Document::factory()->for($project)->create(['title' => 'By Alice']);
    $this->actingAs($alice);
    $byAlice->addMediaFromString('a')->usingFileName('a.txt')->toMediaCollection('attachments');

    $mixed = Document::factory()->for($project)->create(['title' => 'Mixed']);
    $this->actingAs($alice);
    $old = $mixed->addMediaFromString('old')->usingFileName('old.txt')->toMediaCollection('attachments');
    $old->forceFill(['created_at' => now()->subDay()])->save();
    $this->actingAs($bob);
    $mixed->addMediaFromString('new')->usingFileName('new.txt')->toMediaCollection('attachments');

    Document::factory()->for($project)->create(['title' => 'No files']);

    $groups = Livewire::actingAs($viewer)->test('documents.index', ['project' => $project])
        ->set('sortBy', 'author')
        ->get('groupedDocuments');

    expect($groups->keys()->all())->toBe(['', 'Alice', 'Bob'])
        ->and($groups['Alice']->pluck('title')->all())->toBe(['By Alice'])
        ->and($groups['Bob']->pluck('title')->all())->toBe(['Mixed'])
        ->and($groups['']->pluck('title')->all())->toBe(['No files']);
});

test('the author grouping is offered and labels the unknown group', function () {
    $project = Project::factory()->create();
    $viewer = uploaderMember($project, ['view_documents']);
    Document::factory()->for($project)->create(['title' => 'Orphan']);

    Livewire::actingAs($viewer)->test('documents.index', ['project' => $project])
        ->assertSee('作成者')
        ->set('sortBy', 'author')
        ->assertSee('(不明)');
});

test('grouping documents by author does not query per document', function () {
    $project = Project::factory()->create();
    $viewer = uploaderMember($project, ['view_documents']);
    $uploader = User::factory()->create();
    $this->actingAs($uploader);
    foreach (range(1, 6) as $i) {
        Document::factory()->for($project)->create()->addMediaFromString('x'.$i)->usingFileName("f{$i}.txt")->toMediaCollection('attachments');
    }

    $queries = 0;
    Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
        $queries++;
    });

    Livewire::actingAs($viewer)->test('documents.index', ['project' => $project])->set('sortBy', 'author')->get('groupedDocuments');

    expect($queries)->toBeLessThan(25);
});
