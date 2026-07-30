<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use Livewire\Livewire;

function wikiPdfMember(Project $project, array $permissions = ['view_wiki_pages', 'export_wiki_pages']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with export_wiki_pages can export a page as PDF', function () {
    $project = Project::factory()->create();
    $user = wikiPdfMember($project);
    $page = WikiPage::factory()->for($project)->create(['title' => '日本語のページ'.uniqid()]);
    $page->versions()->create(['author_id' => $user->id, 'text' => '説明文です。', 'version' => 2]);

    $component = Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('exportPdf')
        ->assertFileDownloaded("{$page->title}.pdf");

    $content = base64_decode($component->effects['download']['content']);

    // Same discriminating check as IssuePdfTest: "%PDF" alone doesn't prove
    // Japanese text actually rendered — the embedded /BaseFont name does.
    expect(substr($content, 0, 4))->toBe('%PDF')
        ->and($content)->toContain('IPAGothic');
});

test('a member without export_wiki_pages cannot export a page as PDF', function () {
    $project = Project::factory()->create();
    $user = wikiPdfMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('exportPdf')
        ->assertForbidden();
});

test('a member without export_wiki_pages does not see the PDF export button', function () {
    $project = Project::factory()->create();
    $user = wikiPdfMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    // A bare assertDontSee('PDF') would pass for reasons unrelated to the
    // @can('export') gate this button actually lives behind (e.g. "PDF"
    // appearing as a substring anywhere else on the page) — asserting on
    // the wire:click target itself is what ties this to the real gate.
    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->assertDontSee('wire:click="exportPdf"', escape: false);
});
