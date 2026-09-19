<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Attachments\AttachmentPreview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

function previewViewer(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_issues']]));

    return $user;
}

function previewMedia(Issue $issue, string $name, string $content, ?string $mime = null): Media
{
    $adder = $issue->addMediaFromString($content)->usingFileName($name);

    return $adder->toMediaCollection('attachments');
}

beforeEach(fn () => Storage::fake('local'));

test('files are classified the way Redmine chooses a view', function () {
    $issue = Issue::factory()->create();

    expect(AttachmentPreview::kind(previewMedia($issue, 'change.diff', "--- a\n+++ b\n")))->toBe('diff')
        ->and(AttachmentPreview::kind(previewMedia($issue, 'fix.patch', '@@ -1 +1 @@')))->toBe('diff')
        ->and(AttachmentPreview::kind(previewMedia($issue, 'notes.txt', 'hello')))->toBe('text')
        ->and(AttachmentPreview::kind(previewMedia($issue, 'config.yml', 'a: 1')))->toBe('text')
        ->and(AttachmentPreview::kind(previewMedia($issue, 'data.zip', 'PK')))->toBe('other')
        ->and(AttachmentPreview::isPreviewable(previewMedia($issue, 'x.bin', "\x00\x01")))->toBeFalse();

    $image = $issue->addMedia(UploadedFile::fake()->image('photo.png', 20, 20))->toMediaCollection('attachments');
    $pdf = $issue->addMedia(UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4'))->toMediaCollection('attachments');

    expect(AttachmentPreview::kind($image))->toBe('image')
        ->and(AttachmentPreview::isServedInline($image))->toBeTrue()
        ->and(AttachmentPreview::kind($pdf))->toBe('pdf')
        ->and(AttachmentPreview::isServedInline($pdf))->toBeTrue();
});

test('a text file larger than file_max_size_displayed falls back to the details page', function () {
    Setting::set('file_max_size_displayed', 1);
    $issue = Issue::factory()->create();

    expect(AttachmentPreview::kind(previewMedia($issue, 'big.txt', str_repeat('x', 2048))))->toBe('other')
        ->and(AttachmentPreview::kind(previewMedia($issue, 'big.diff', str_repeat('+', 2048))))->toBe('other');

    Setting::set('file_max_size_displayed', 0);
    expect(AttachmentPreview::kind(previewMedia($issue, 'big2.txt', str_repeat('x', 2048))))->toBe('text');
});

test('a text attachment shows its content and the pager links to its neighbours', function () {
    $project = Project::factory()->create();
    $viewer = previewViewer($project);
    $issue = Issue::factory()->for($project)->create();
    $first = previewMedia($issue, 'first.txt', 'the FIRST file');
    $second = previewMedia($issue, 'second.txt', 'the <b>second</b> file');
    $third = previewMedia($issue, 'third.txt', 'the third');

    $response = $this->actingAs($viewer)->get(route('attachments.preview', $second))->assertOk();

    $response->assertSee('second.txt')->assertSee('the &lt;b&gt;second&lt;/b&gt; file', false)->assertSee('2 / 3');
    expect($response->getContent())->toContain(route('attachments.preview', $first))->toContain(route('attachments.preview', $third))->toContain(route('attachments.show', $second));

    $this->actingAs($viewer)->get(route('attachments.preview', $first))->assertDontSee('« 前へ')->assertSee('次へ »');
});

test('a diff is coloured by line kind', function () {
    $project = Project::factory()->create();
    $viewer = previewViewer($project);
    $issue = Issue::factory()->for($project)->create();
    $diff = previewMedia($issue, 'change.patch', "--- a/f\n+++ b/f\n@@ -1 +1 @@\n-old\n+new\n");

    $html = $this->actingAs($viewer)->get(route('attachments.preview', $diff))->assertOk()->getContent();

    expect($html)->toContain('data-attachment-diff')->toContain('text-green-400">+new')->toContain('text-red-400">-old')->toContain('text-cyan-400">@@ -1 +1 @@');
});

test('a legacy-encoded text file is converted when an encoding is configured', function () {
    Setting::set('repositories_encodings', 'SJIS-win');
    $project = Project::factory()->create();
    $viewer = previewViewer($project);
    $issue = Issue::factory()->for($project)->create();
    $media = previewMedia($issue, 'jp.txt', mb_convert_encoding('日本語のメモ', 'SJIS-win', 'UTF-8'));

    $this->actingAs($viewer)->get(route('attachments.preview', $media))->assertOk()->assertSee('日本語のメモ');
});

test('other files show only their details and a download link', function () {
    $project = Project::factory()->create();
    $viewer = previewViewer($project);
    $issue = Issue::factory()->for($project)->create();
    $media = previewMedia($issue, 'archive.zip', 'PK data');

    $this->actingAs($viewer)->get(route('attachments.preview', $media))->assertOk()->assertSee('表示できません')->assertSee('archive.zip');
});

test('images and PDFs are streamed inline with a sandboxing policy, other types are refused', function () {
    $project = Project::factory()->create();
    $viewer = previewViewer($project);
    $issue = Issue::factory()->for($project)->create();
    $image = $issue->addMedia(UploadedFile::fake()->image('photo.png', 20, 20))->toMediaCollection('attachments');
    $script = previewMedia($issue, 'notes.txt', '<script>alert(1)</script>');

    $inline = $this->actingAs($viewer)->get(route('attachments.inline', $image))->assertOk();

    expect($inline->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($inline->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($inline->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    $this->actingAs($viewer)->get(route('attachments.inline', $script))->assertNotFound();
    $this->actingAs($viewer)->get(route('attachments.preview', $image))->assertOk()->assertSee(route('attachments.inline', $image), false);
});

test('viewing follows the record visibility', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $issue = Issue::factory()->for($project)->create();
    $media = previewMedia($issue, 'secret.txt', 'classified');
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('attachments.preview', $media))->assertForbidden();
    $this->actingAs($outsider)->get(route('attachments.inline', $media))->assertForbidden();
});

test('guests are sent to the login page', function () {
    $media = previewMedia(Issue::factory()->create(), 'a.txt', 'x');

    $this->get(route('attachments.preview', $media))->assertRedirect();
});

test('the issue page offers the view link only for previewable files', function () {
    $project = Project::factory()->create();
    $viewer = previewViewer($project);
    $issue = Issue::factory()->for($project)->create();
    previewMedia($issue, 'notes.txt', 'text');
    previewMedia($issue, 'bundle.zip', 'PK');

    $html = Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->html();

    expect(substr_count($html, 'data-attachment-preview-link'))->toBe(1);
});
