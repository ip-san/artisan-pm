<?php

use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use Laravel\Passport\Passport;
use Livewire\Livewire;

test('the project form saves the homepage', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $project->trackers()->sync([Tracker::factory()->create()->id]);

    Livewire::actingAs($admin)->test('projects.form', ['project' => $project])
        ->assertSet('homepage', '')
        ->set('homepage', 'https://example.com/docs')
        ->call('save')
        ->assertHasNoErrors();

    expect($project->fresh()->homepage)->toBe('https://example.com/docs');
});

test('the project form rejects a homepage longer than 255 characters', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $project->trackers()->sync([Tracker::factory()->create()->id]);

    Livewire::actingAs($admin)->test('projects.form', ['project' => $project])
        ->set('homepage', 'https://example.com/'.str_repeat('a', 240))
        ->call('save')
        ->assertHasErrors(['homepage' => 'max']);
});

test('the overview links a safe homepage and shows an unsafe one as plain text', function () {
    $admin = User::factory()->admin()->create();
    $safe = Project::factory()->create(['homepage' => 'https://example.com/docs']);
    $unsafe = Project::factory()->create(['homepage' => 'javascript:alert(1)']);

    $this->actingAs($admin)->get(route('projects.show', $safe))
        ->assertOk()
        ->assertSee('href="https://example.com/docs"', false);

    $this->actingAs($admin)->get(route('projects.show', $unsafe))
        ->assertOk()
        ->assertSee('javascript:alert(1)')
        ->assertDontSee('href="javascript:alert(1)"', false);
});

test('homepageUrl only returns links with an allowed scheme', function (?string $homepage, ?string $expected) {
    expect((new Project(['homepage' => $homepage]))->homepageUrl())->toBe($expected);
})->with([
    'https' => ['https://example.com', 'https://example.com'],
    'mailto' => ['mailto:team@example.com', 'mailto:team@example.com'],
    'ftp' => ['ftp://files.example.com', 'ftp://files.example.com'],
    'javascript' => ['javascript:alert(1)', null],
    'data' => ['data:text/html,<b>x</b>', null],
    'no scheme' => ['example.com', null],
    'blank' => ['   ', null],
    'null' => [null, null],
]);

test('the REST API reads and writes the homepage', function () {
    $admin = User::factory()->admin()->create();
    Passport::actingAs($admin);
    $tracker = Tracker::factory()->create();

    $created = $this->postJson('/api/v1/projects', [
        'name' => 'Homepage project',
        'identifier' => 'homepage-project',
        'homepage' => 'https://example.com',
        'tracker_ids' => [$tracker->id],
    ])->assertCreated()->assertJsonPath('data.homepage', 'https://example.com');

    $this->putJson('/api/v1/projects/'.$created->json('data.id'), ['homepage' => 'https://example.org'])
        ->assertOk()
        ->assertJsonPath('data.homepage', 'https://example.org');

    $this->putJson('/api/v1/projects/'.$created->json('data.id'), ['homepage' => str_repeat('a', 256)])
        ->assertUnprocessable();
});
