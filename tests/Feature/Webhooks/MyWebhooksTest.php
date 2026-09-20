<?php

use App\Enums\WebhookEvent;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function myWebhookMember(Project $project, array $permissions = ['use_webhooks']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('a user without use_webhooks cannot open the page', function () {
    $project = Project::factory()->create();
    $user = myWebhookMember($project, ['view_issues']);

    $this->actingAs($user)->get(route('my-webhooks.index'))->assertForbidden();
});

test('the page is closed while webhooks are disabled', function () {
    Setting::set('webhooks_enabled', false);
    $user = myWebhookMember(Project::factory()->create());

    $this->actingAs($user)->get(route('my-webhooks.index'))->assertForbidden();
});

test('a user with use_webhooks lists only their own hooks', function () {
    $project = Project::factory()->create();
    $user = myWebhookMember($project);
    Webhook::factory()->create(['user_id' => $user->id, 'url' => 'https://mine.example.com/hook']);
    Webhook::factory()->create(['user_id' => null, 'url' => 'https://admin.example.com/hook']);
    Webhook::factory()->create(['user_id' => User::factory()->create()->id, 'url' => 'https://other.example.com/hook']);

    $this->actingAs($user)->get(route('my-webhooks.index'))
        ->assertOk()
        ->assertSee('mine.example.com')
        ->assertDontSee('admin.example.com')
        ->assertDontSee('other.example.com');
});

test('creating a hook fixes the owner to the current user', function () {
    $project = Project::factory()->create();
    $user = myWebhookMember($project);

    Livewire::actingAs($user)->test('my-webhooks.index')
        ->call('startCreate')
        ->set('url', 'https://8.8.8.8/hook')
        ->set('secret', 's3cret')
        ->set('project_id', $project->id)
        ->set('events', [WebhookEvent::IssueCreated->value])
        ->call('save')
        ->assertHasNoErrors();

    $hook = Webhook::query()->sole();
    expect($hook->user_id)->toBe($user->id)
        ->and($hook->project_id)->toBe($project->id)
        ->and($hook->secret)->toBe('s3cret');
});

test('a project without use_webhooks cannot be chosen', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $user = myWebhookMember($project);

    Livewire::actingAs($user)->test('my-webhooks.index')
        ->call('startCreate')
        ->set('url', 'https://8.8.8.8/hook')
        ->set('project_id', $other->id)
        ->set('events', [WebhookEvent::IssueCreated->value])
        ->call('save')
        ->assertHasErrors(['project_id']);

    expect(Webhook::query()->count())->toBe(0);
});

test('a hook cannot aim at the local network', function (string $url) {
    $user = myWebhookMember(Project::factory()->create());

    Livewire::actingAs($user)->test('my-webhooks.index')
        ->call('startCreate')
        ->set('url', $url)
        ->set('events', [WebhookEvent::IssueCreated->value])
        ->call('save')
        ->assertHasErrors(['url']);
})->with([
    'loopback' => 'http://127.0.0.1/hook',
    'localhost' => 'http://localhost:8080/hook',
    'private' => 'http://10.0.0.5/hook',
    'link-local' => 'http://169.254.169.254/latest/meta-data',
    'ftp' => 'ftp://8.8.8.8/hook',
]);

test('editing keeps the secret when left blank and never edits someone else\'s hook', function () {
    $project = Project::factory()->create();
    $user = myWebhookMember($project);
    $mine = Webhook::factory()->create(['user_id' => $user->id, 'secret' => 'keep-me', 'is_active' => true]);
    $theirs = Webhook::factory()->create(['user_id' => User::factory()->create()->id]);

    $component = Livewire::actingAs($user)->test('my-webhooks.index')
        ->call('startEdit', $mine->id)
        ->assertSet('secret', '')
        ->set('url', 'https://8.8.4.4/new')
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    $mine->refresh();
    expect($mine->url)->toBe('https://8.8.4.4/new')
        ->and($mine->secret)->toBe('keep-me')
        ->and($mine->is_active)->toBeFalse();

    expect(fn () => $component->call('startEdit', $theirs->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->call('delete', $theirs->id))->toThrow(ModelNotFoundException::class);
    expect($theirs->fresh())->not->toBeNull();
});

test('a user deletes their own hook', function () {
    $user = myWebhookMember(Project::factory()->create());
    $mine = Webhook::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test('my-webhooks.index')->call('delete', $mine->id);

    expect(Webhook::query()->count())->toBe(0);
});

test('the account page links to the page only for users with use_webhooks', function () {
    $project = Project::factory()->create();

    $this->actingAs(myWebhookMember($project))->get(route('profile.index'))->assertSee(route('my-webhooks.index'));
    $this->actingAs(myWebhookMember($project, ['view_issues']))->get(route('profile.index'))->assertDontSee(route('my-webhooks.index'));
});
