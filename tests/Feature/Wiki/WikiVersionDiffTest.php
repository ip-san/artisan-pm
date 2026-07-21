<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use App\Services\WikiPageService;
use App\Support\Diff\WordDiffer;
use Livewire\Livewire;

function wikiDiffMember(Project $project, array $permissions = ['view_wiki_pages']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('WordDiffer marks added and removed words correctly', function () {
    $diff = app(WordDiffer::class)->diff('the quick fox', 'the quick brown fox jumps');

    expect($diff)->toBe([
        ['type' => 'same', 'text' => 'the'],
        ['type' => 'same', 'text' => ' '],
        ['type' => 'same', 'text' => 'quick'],
        ['type' => 'add', 'text' => ' '],
        ['type' => 'add', 'text' => 'brown'],
        ['type' => 'same', 'text' => ' '],
        ['type' => 'same', 'text' => 'fox'],
        ['type' => 'add', 'text' => ' '],
        ['type' => 'add', 'text' => 'jumps'],
    ]);
});

test('WordDiffer returns an unchanged diff when the texts are identical', function () {
    $diff = app(WordDiffer::class)->diff('hello world', 'hello world');

    expect(collect($diff)->every(fn (array $chunk) => $chunk['type'] === 'same'))->toBeTrue();
});

test('the diff page shows additions and deletions between two versions', function () {
    $project = Project::factory()->create();
    $user = wikiDiffMember($project);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'the quick fox', $author);
    app(WikiPageService::class)->update($page, [], 'the quick brown fox jumps', $author);

    $component = Livewire::actingAs($user)
        ->test('wiki.diff', ['project' => $project, 'wikiPage' => $page, 'from' => 1, 'to' => 2]);

    $component->assertOk();

    expect($component->get('versionFrom')->version)->toBe(1)
        ->and($component->get('versionTo')->version)->toBe(2);

    $diff = $component->get('diff');
    $added = collect($diff)->where('type', 'add')->pluck('text')->implode('');
    expect($added)->toContain('brown')->toContain('jumps');
});

test('from and to are normalized regardless of URL order', function () {
    $project = Project::factory()->create();
    $user = wikiDiffMember($project);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'version one', $author);
    app(WikiPageService::class)->update($page, [], 'version two', $author);

    $component = Livewire::actingAs($user)
        ->test('wiki.diff', ['project' => $project, 'wikiPage' => $page, 'from' => 2, 'to' => 1]);

    expect($component->get('versionFrom')->version)->toBe(1)
        ->and($component->get('versionTo')->version)->toBe(2);
});

test('a nonexistent version number 404s', function () {
    $project = Project::factory()->create();
    $user = wikiDiffMember($project);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.diff', ['project' => $project, 'wikiPage' => $page, 'from' => 1, 'to' => 999])
        ->assertStatus(404);
});

test('a member without view_wiki_pages cannot view the diff', function () {
    $project = Project::factory()->create();
    $user = wikiDiffMember($project, []);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'v1', $author);
    app(WikiPageService::class)->update($page, [], 'v2', $author);

    Livewire::actingAs($user)
        ->test('wiki.diff', ['project' => $project, 'wikiPage' => $page, 'from' => 1, 'to' => 2])
        ->assertForbidden();
});

test('the history page links to the diff between the two most recently selected versions by default', function () {
    $project = Project::factory()->create();
    $user = wikiDiffMember($project);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'v1', $author);
    app(WikiPageService::class)->update($page, [], 'v2', $author);

    Livewire::actingAs($user)
        ->test('wiki.history', ['project' => $project, 'wikiPage' => $page])
        ->assertSee(route('wiki.diff', [$project, $page, 'from' => 1, 'to' => 2]));
});
