<?php

use App\Enums\WebhookEvent;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Webhook;
use App\Support\Webhooks\WebhookEndpoint;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Livewire\Livewire;
use Psr\Http\Message\RequestInterface;

/**
 * Binds a Guzzle client that answers 200 and records every request it is
 * asked to send, with its options.
 *
 * @return ArrayObject<int, array{request: RequestInterface, options: array<string, mixed>}>
 */
function recordWebhookRequests(): ArrayObject
{
    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler(array_fill(0, 5, new Response(200))));
    $stack->push(Middleware::history($history));
    app()->instance(Client::class, new Client(['handler' => $stack]));

    return $history;
}

/**
 * @param  array<string, list<string>>  $answers
 */
function resolveWebhookHosts(array $answers): void
{
    WebhookEndpoint::resolveUsing(fn (string $host): array => $answers[$host] ?? []);
}

afterEach(function () {
    WebhookEndpoint::resolveUsing(null);
});

test('a hook whose host now resolves to a refused address is not called', function (string $address, bool $owned) {
    $history = recordWebhookRequests();
    resolveWebhookHosts(['hooks.example.test' => [$address]]);
    $webhook = Webhook::factory()->create([
        'url' => 'https://hooks.example.test/in',
        'user_id' => $owned ? User::factory()->create()->id : null,
    ]);

    $webhook->deliver(['event' => 'issue.created']);

    expect($history)->toHaveCount(0);
})->with([
    'loopback' => ['127.0.0.1', false],
    'unspecified' => ['0.0.0.0', false],
    'link-local metadata' => ['169.254.169.254', false],
    'multicast' => ['224.0.0.1', false],
    'IPv6 loopback' => ['::1', false],
    'IPv6 link-local' => ['fe80::1', false],
    'IPv4-mapped loopback' => ['::ffff:127.0.0.1', false],
    'private, owned by a user' => ['10.0.0.5', true],
    'IPv6 unique local, owned by a user' => ['fd00::1', true],
]);

test('one refused address among several refuses the host', function () {
    $history = recordWebhookRequests();
    resolveWebhookHosts(['hooks.example.test' => ['93.184.216.34', '::1']]);
    Webhook::factory()->create(['url' => 'https://hooks.example.test/in'])->deliver(['event' => 'issue.created']);

    expect($history)->toHaveCount(0);
});

test('a host that no longer resolves is not called', function () {
    $history = recordWebhookRequests();
    resolveWebhookHosts([]);
    Webhook::factory()->create(['url' => 'https://gone.example.test/in'])->deliver(['event' => 'issue.created']);

    expect($history)->toHaveCount(0);
});

test('a hook on a port browsers refuse is not called', function () {
    $history = recordWebhookRequests();
    Webhook::factory()->create(['url' => 'http://93.184.216.34:25/in'])->deliver(['event' => 'issue.created']);

    expect($history)->toHaveCount(0);
});

test('an administrator\'s hook may still reach a private address', function () {
    $history = recordWebhookRequests();
    resolveWebhookHosts(['ci.internal.test' => ['10.0.0.5']]);
    Webhook::factory()->create(['url' => 'http://ci.internal.test:8080/hook', 'user_id' => null])->deliver(['event' => 'issue.created']);

    expect($history)->toHaveCount(1)
        ->and($history[0]['options']['curl'][CURLOPT_RESOLVE])->toBe(['ci.internal.test:8080:10.0.0.5']);
});

test('a safe hook is called on the checked address without following redirects', function () {
    $history = recordWebhookRequests();
    resolveWebhookHosts(['hooks.example.test' => ['2606:2800:220:1:248:1893:25c8:1946']]);
    $webhook = Webhook::factory()->create(['url' => 'https://hooks.example.test/in', 'user_id' => User::factory()->create()->id]);

    $webhook->deliver(['event' => 'issue.created']);

    expect($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri())->toBe('https://hooks.example.test/in')
        ->and($history[0]['options']['allow_redirects'])->toBeFalse()
        ->and($history[0]['options']['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.test:443:[2606:2800:220:1:248:1893:25c8:1946]']);
});

test('saving a hook refuses a host name that resolves to the local network', function () {
    Setting::set('webhooks_enabled', true);
    resolveWebhookHosts(['sneaky.example.test' => ['93.184.216.34', '::1']]);
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['use_webhooks', 'view_issues']]));

    Livewire::actingAs($user)->test('my-webhooks.index')
        ->call('startCreate')
        ->set('url', 'https://sneaky.example.test/hook')
        ->set('events', [WebhookEvent::IssueCreated->value])
        ->call('save')
        ->assertHasErrors(['url']);
});
