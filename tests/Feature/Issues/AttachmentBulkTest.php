<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Attachments\AttachmentRenamer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function bulkAttachmentMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

function bulkAttachmentIssue(Project $project, array $files): Issue
{
    $issue = Issue::factory()->for($project)->create();

    foreach ($files as $name => $content) {
        $issue->addMediaFromString($content)->usingFileName($name)->toMediaCollection('attachments');
    }

    return $issue;
}

/**
 * @return array<int, string>
 */
function bulkZipEntries(string $binary): array
{
    $path = tempnam(sys_get_temp_dir(), 'zip-test-');
    file_put_contents($path, $binary);
    $zip = new ZipArchive;
    $zip->open($path);
    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $zip->close();
    unlink($path);

    return $names;
}

beforeEach(fn () => Storage::fake('local'));

test('every attachment of an issue downloads as one zip, duplicate names get a counter', function () {
    $project = Project::factory()->create();
    $user = bulkAttachmentMember($project, ['view_project', 'view_issues']);
    $issue = bulkAttachmentIssue($project, ['a.txt' => 'first']);
    $issue->addMediaFromString('second')->usingFileName('a.txt')->toMediaCollection('attachments');
    $issue->addMediaFromString('third')->usingFileName('b')->toMediaCollection('attachments');
    $issue->addMediaFromString('fourth')->usingFileName('b')->toMediaCollection('attachments');

    $response = $this->actingAs($user)->get(route('attachments.download-all', ['issue', $issue->id]));

    $response->assertOk()->assertDownload("issue-{$issue->id}-attachments.zip");
    expect(bulkZipEntries($response->baseResponse->getFile()->getContent()))->toEqualCanonicalizing(['a.txt', 'a(1).txt', 'b', 'b(1)']);
});

test('the zip is refused above bulk_download_max_size and 0 lifts the limit', function () {
    Setting::set('bulk_download_max_size', 1);
    $project = Project::factory()->create();
    $user = bulkAttachmentMember($project, ['view_project', 'view_issues']);
    $issue = bulkAttachmentIssue($project, ['big.txt' => str_repeat('x', 2048), 'small.txt' => 'y']);

    $this->actingAs($user)->from('/somewhere')->get(route('attachments.download-all', ['issue', $issue->id]))
        ->assertRedirect('/somewhere')
        ->assertSessionHas('error');

    Setting::set('bulk_download_max_size', 0);
    $this->actingAs($user)->get(route('attachments.download-all', ['issue', $issue->id]))->assertOk();
});

test('a viewer without access, an unknown type and an empty record all get refused', function () {
    $project = Project::factory()->create();
    $outsider = User::factory()->create();
    $member = bulkAttachmentMember($project, ['view_project', 'view_issues']);
    $issue = bulkAttachmentIssue($project, ['a.txt' => 'a']);
    $empty = bulkAttachmentIssue($project, []);

    $this->actingAs($outsider)->get(route('attachments.download-all', ['issue', $issue->id]))->assertForbidden();
    $this->actingAs($member)->get('/attachments/widget/1/download')->assertNotFound();
    $this->actingAs($member)->get(route('attachments.download-all', ['issue', $empty->id]))->assertNotFound();
});

test('a guest is sent to the login page instead of receiving the zip', function () {
    $issue = bulkAttachmentIssue(Project::factory()->create(), ['a.txt' => 'a']);

    $this->get(route('attachments.download-all', ['issue', $issue->id]))->assertRedirect();
});

test('news attachments can be bundled too', function () {
    $project = Project::factory()->create();
    $user = bulkAttachmentMember($project, ['view_project', 'view_news']);
    $news = News::factory()->for($project)->create();
    $news->addMediaFromString('one')->usingFileName('one.txt')->toMediaCollection('attachments');

    $this->actingAs($user)->get(route('attachments.download-all', ['news', $news->id]))->assertOk();
});

test('the links show up on the issue page: download needs two files, edit needs update rights', function () {
    $project = Project::factory()->create();
    $viewer = bulkAttachmentMember($project, ['view_project', 'view_issues']);
    $editor = bulkAttachmentMember($project, ['view_project', 'view_issues', 'edit_issues']);
    $two = bulkAttachmentIssue($project, ['a.txt' => 'a', 'b.txt' => 'b']);
    $one = bulkAttachmentIssue($project, ['a.txt' => 'a']);

    $html = fn (User $user, Issue $issue) => Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $issue])->html();

    expect($html($viewer, $two))->toContain('attachments/issue/'.$two->id.'/download')->not->toContain('/edit')
        ->and($html($viewer, $one))->not->toContain('data-attachment-bulk-links')
        ->and($html($editor, $one))->toContain('attachments/issue/'.$one->id.'/edit')->not->toContain('/download');
});

test('the edit page renames files and updates descriptions', function () {
    $project = Project::factory()->create();
    $editor = bulkAttachmentMember($project, ['view_project', 'view_issues', 'edit_issues']);
    $issue = bulkAttachmentIssue($project, ['old.txt' => 'content', 'keep.txt' => 'stay']);
    [$old, $keep] = $issue->getMedia('attachments')->all();

    Livewire::actingAs($editor)->test('attachments.edit-all', ['type' => 'issue', 'id' => $issue->id])
        ->assertSet("names.{$old->id}", 'old.txt')
        ->set("names.{$old->id}", 'renamed.txt')
        ->set("descriptions.{$old->id}", 'A description')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('issues.show', [$project, $issue]));

    $old->refresh();
    $keep->refresh();
    expect($old->file_name)->toBe('renamed.txt')
        ->and($old->name)->toBe('renamed')
        ->and($old->getCustomProperty('description'))->toBe('A description')
        ->and(Storage::disk('local')->get($old->getPathRelativeToRoot()))->toBe('content')
        ->and($keep->file_name)->toBe('keep.txt');
});

test('renaming rejects path tricks, blank names and forbidden extensions, and leaves everything unchanged', function () {
    Setting::set('attachment_extensions_denied', 'exe');
    $project = Project::factory()->create();
    $editor = bulkAttachmentMember($project, ['view_project', 'view_issues', 'edit_issues']);
    $issue = bulkAttachmentIssue($project, ['a.txt' => 'a']);
    $media = $issue->getMedia('attachments')->first();

    foreach (['../evil.txt', 'dir/x.txt', '', 'virus.exe'] as $bad) {
        Livewire::actingAs($editor)->test('attachments.edit-all', ['type' => 'issue', 'id' => $issue->id])
            ->set("names.{$media->id}", $bad)
            ->call('save')
            ->assertHasErrors(["names.{$media->id}"]);
    }

    expect($media->fresh()->file_name)->toBe('a.txt');
});

test('a tampered media id from another record is ignored', function () {
    $project = Project::factory()->create();
    $editor = bulkAttachmentMember($project, ['view_project', 'view_issues', 'edit_issues']);
    $issue = bulkAttachmentIssue($project, ['a.txt' => 'a']);
    $other = bulkAttachmentIssue($project, ['other.txt' => 'o']);
    $foreign = $other->getMedia('attachments')->first();

    Livewire::actingAs($editor)->test('attachments.edit-all', ['type' => 'issue', 'id' => $issue->id])
        ->set("names.{$foreign->id}", 'hijacked.txt')
        ->call('save');

    expect($foreign->fresh()->file_name)->toBe('other.txt');
});

test('only someone who may update the record can open the edit page', function () {
    $project = Project::factory()->create();
    $viewer = bulkAttachmentMember($project, ['view_project', 'view_issues']);
    $issue = bulkAttachmentIssue($project, ['a.txt' => 'a']);

    Livewire::actingAs($viewer)->test('attachments.edit-all', ['type' => 'issue', 'id' => $issue->id])->assertForbidden();
    Livewire::actingAs($viewer)->test('attachments.edit-all', ['type' => 'nonsense', 'id' => 1])->assertNotFound();
});

test('the renamer validates names', function () {
    expect(AttachmentRenamer::isValidName('report v2.pdf'))->toBeTrue()
        ->and(AttachmentRenamer::isValidName('日本語.txt'))->toBeTrue()
        ->and(AttachmentRenamer::isValidName('a/b.txt'))->toBeFalse()
        ->and(AttachmentRenamer::isValidName('..'))->toBeFalse()
        ->and(AttachmentRenamer::isValidName("bad\0name"))->toBeFalse();
});

test('the settings form saves the bulk download limit', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('bulk_download_max_size', 2048)->call('save')->assertHasNoErrors();
    expect(Setting::get('bulk_download_max_size'))->toBe(2048);

    Livewire::actingAs($admin)->test('settings.index')->set('bulk_download_max_size', -1)->call('save')->assertHasErrors(['bulk_download_max_size']);
});

test('renaming an image moves its thumbnail along with it', function () {
    $project = Project::factory()->create();
    $editor = bulkAttachmentMember($project, ['view_project', 'view_issues', 'edit_issues']);
    $issue = Issue::factory()->for($project)->create();
    $issue->addMedia(UploadedFile::fake()->image('photo.png', 300, 300))->toMediaCollection('attachments');
    $media = $issue->getMedia('attachments')->first();
    $oldThumb = $media->getPathRelativeToRoot('thumb');
    expect(Storage::disk('local')->exists($oldThumb))->toBeTrue();

    Livewire::actingAs($editor)->test('attachments.edit-all', ['type' => 'issue', 'id' => $issue->id])
        ->set("names.{$media->id}", 'picture.png')
        ->call('save')
        ->assertHasNoErrors();

    $media->refresh();
    expect($media->file_name)->toBe('picture.png')
        ->and(Storage::disk('local')->exists($media->getPathRelativeToRoot()))->toBeTrue()
        ->and(Storage::disk('local')->exists($media->getPathRelativeToRoot('thumb')))->toBeTrue()
        ->and(Storage::disk('local')->exists($oldThumb))->toBeFalse();
});
