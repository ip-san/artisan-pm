<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use Livewire\Livewire;

function wikiBulkPdfMember(Project $project, array $permissions = ['view_wiki_pages', 'export_wiki_pages']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with export_wiki_pages can download the whole wiki as one combined PDF', function () {
    $project = Project::factory()->create();
    $user = wikiBulkPdfMember($project);
    $first = WikiPage::factory()->for($project)->create(['title' => '最初のページ']);
    $first->versions()->create(['author_id' => $user->id, 'text' => '最初の内容。', 'version' => 2]);
    $second = WikiPage::factory()->for($project)->create(['title' => '次のページ']);
    $second->versions()->create(['author_id' => $user->id, 'text' => '次の内容。', 'version' => 2]);

    $component = Livewire::actingAs($user)
        ->test('wiki.pages', ['project' => $project])
        ->call('exportPdf')
        ->assertFileDownloaded("{$project->identifier}.pdf");

    $content = base64_decode($component->effects['download']['content']);

    expect(substr($content, 0, 4))->toBe('%PDF')
        ->and($content)->toContain('IPAGothic');
});

test('a member without export_wiki_pages cannot download the combined PDF', function () {
    $project = Project::factory()->create();
    $user = wikiBulkPdfMember($project, ['view_wiki_pages']);
    WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.pages', ['project' => $project])
        ->call('exportPdf')
        ->assertForbidden();
});

test('a member without export_wiki_pages does not see the PDF export link', function () {
    $project = Project::factory()->create();
    $user = wikiBulkPdfMember($project, ['view_wiki_pages']);
    WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.pages', ['project' => $project])
        ->assertDontSee('wire:click="exportPdf"', escape: false);
});

test('a child page is included right after its parent, before the next root page', function () {
    $project = Project::factory()->create();
    $user = wikiBulkPdfMember($project);
    $root = WikiPage::factory()->for($project)->create(['title' => 'Aルート']);
    $root->versions()->create(['author_id' => $user->id, 'text' => 'ルート内容', 'version' => 2]);
    $child = WikiPage::factory()->for($project)->for($root, 'parent')->create(['title' => 'A子ページ']);
    $child->versions()->create(['author_id' => $user->id, 'text' => '子内容', 'version' => 2]);
    $otherRoot = WikiPage::factory()->for($project)->create(['title' => 'Bルート']);
    $otherRoot->versions()->create(['author_id' => $user->id, 'text' => '別ルート内容', 'version' => 2]);

    // hierarchicalOrder() is private (it's an implementation detail of
    // exportPdf(), not a public API), but the ordering it produces is the
    // one behavior this test exists to pin down, so it's reached directly
    // via reflection rather than round-tripping through a full PDF render
    // and re-extracting text order from the binary output.
    $component = Livewire::actingAs($user)->test('wiki.pages', ['project' => $project]);
    $method = new ReflectionMethod($component->instance(), 'hierarchicalOrder');
    $method->setAccessible(true);
    $result = $method->invoke($component->instance(), $project->wikiPages()->get());

    $titles = $result->pluck('page.title')->all();

    expect($titles)->toBe(['Aルート', 'A子ページ', 'Bルート']);
});

test('an empty wiki still produces a downloadable PDF', function () {
    $project = Project::factory()->create();
    $user = wikiBulkPdfMember($project);

    Livewire::actingAs($user)
        ->test('wiki.pages', ['project' => $project])
        ->call('exportPdf')
        ->assertFileDownloaded("{$project->identifier}.pdf");
});
