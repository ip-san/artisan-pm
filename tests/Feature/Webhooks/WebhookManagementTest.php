<?php

use App\Enums\WebhookEvent;
use App\Models\Project;
use App\Models\User;
use App\Models\Webhook;
use Livewire\Livewire;

test('an admin can create a webhook', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('webhooks.form')
        ->set('name', 'Issue tracker sync')
        ->set('url', 'https://example.com/hooks/issues')
        ->set('events', [WebhookEvent::IssueCreated->value])
        ->call('save')
        ->assertRedirect(route('webhooks.index'));

    $webhook = Webhook::where('name', 'Issue tracker sync')->firstOrFail();

    expect($webhook->url)->toBe('https://example.com/hooks/issues')
        ->and($webhook->events)->toBe([WebhookEvent::IssueCreated->value])
        ->and($webhook->project_id)->toBeNull();
});

test('a non-admin cannot access webhook administration', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('webhooks.index')->assertForbidden();
    Livewire::actingAs($user)->test('webhooks.form')->assertForbidden();
});

test('a webhook requires at least one event', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('webhooks.form')
        ->set('name', 'No events')
        ->set('url', 'https://example.com/hooks')
        ->call('save')
        ->assertHasErrors(['events']);
});

test('leaving the secret blank on edit keeps the existing secret', function () {
    $admin = User::factory()->admin()->create();
    $webhook = Webhook::factory()->create(['secret' => 'original-secret']);

    Livewire::actingAs($admin)
        ->test('webhooks.form', ['webhook' => $webhook])
        ->set('name', 'Renamed')
        ->call('save');

    expect($webhook->refresh()->secret)->toBe('original-secret')
        ->and($webhook->name)->toBe('Renamed');
});

test('a webhook can be scoped to a single project', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();

    Livewire::actingAs($admin)
        ->test('webhooks.form')
        ->set('name', 'Scoped hook')
        ->set('url', 'https://example.com/hooks')
        ->set('project_id', $project->id)
        ->set('events', [WebhookEvent::IssueCreated->value])
        ->call('save');

    $webhook = Webhook::where('name', 'Scoped hook')->firstOrFail();

    expect($webhook->project_id)->toBe($project->id)
        ->and($webhook->appliesToProject($project))->toBeTrue();

    $otherProject = Project::factory()->create();
    expect($webhook->appliesToProject($otherProject))->toBeFalse();
});

test('an admin can delete a webhook', function () {
    $admin = User::factory()->admin()->create();
    $webhook = Webhook::factory()->create();

    Livewire::actingAs($admin)->test('webhooks.index')->call('delete', $webhook->id);

    expect(Webhook::find($webhook->id))->toBeNull();
});
