<?php

use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Laravel\Passport\Passport;
use Livewire\Livewire;

function jsonpProject(): Project
{
    return Project::factory()->create(['name' => 'Jsonp project']);
}

test('with jsonp disabled a callback changes nothing', function () {
    $project = jsonpProject();
    Passport::actingAs(User::factory()->admin()->create());

    $response = $this->getJson("/api/v1/projects/{$project->id}?callback=cb")->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->getContent())->not->toStartWith('cb(');
});

test('with jsonp enabled the JSON is wrapped in the callback', function () {
    Setting::set('jsonp_enabled', true);
    $project = jsonpProject();
    Passport::actingAs(User::factory()->admin()->create());

    $response = $this->getJson("/api/v1/projects/{$project->id}?callback=handle_data")->assertOk();
    $body = $response->getContent();

    expect($body)->toStartWith('handle_data(')->toEndWith(')')
        ->and($response->headers->get('Content-Type'))->toContain('application/javascript')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(json_decode(substr($body, strlen('handle_data('), -1), true)['data']['name'])->toBe('Jsonp project');
});

test('the jsonp parameter works too and the callback is stripped down to safe characters', function () {
    Setting::set('jsonp_enabled', true);
    $project = jsonpProject();
    Passport::actingAs(User::factory()->admin()->create());

    expect($this->getJson("/api/v1/projects/{$project->id}?jsonp=my.cb")->getContent())->toStartWith('my.cb(')
        ->and($this->getJson("/api/v1/projects/{$project->id}?callback=".urlencode('a;alert(1)//b'))->getContent())->toStartWith('aalert1b(');
});

test('a callback that empties out is ignored', function () {
    Setting::set('jsonp_enabled', true);
    $project = jsonpProject();
    Passport::actingAs(User::factory()->admin()->create());

    $response = $this->getJson("/api/v1/projects/{$project->id}?callback=".urlencode('();<>'))->assertOk();

    expect($response->getContent())->toStartWith('{')->and($response->headers->get('Content-Type'))->toContain('application/json');
});

test('errors and non-GET requests are never wrapped', function () {
    Setting::set('jsonp_enabled', true);
    Passport::actingAs(User::factory()->create());

    $missing = $this->getJson('/api/v1/projects/999999?callback=cb');
    expect($missing->getStatusCode())->toBe(404)->and($missing->getContent())->not->toStartWith('cb(');

    Passport::actingAs(User::factory()->admin()->create());
    $created = $this->postJson('/api/v1/projects?callback=cb', ['name' => 'Posted', 'identifier' => 'posted-jsonp']);
    expect($created->getContent())->not->toStartWith('cb(');
});

test('the settings form saves the option and it defaults to off', function () {
    expect(Setting::get('jsonp_enabled', false))->toBeFalse();

    $admin = User::factory()->admin()->create();
    Livewire::actingAs($admin)->test('settings.index')->assertSet('jsonp_enabled', false)->set('jsonp_enabled', true)->call('save')->assertHasNoErrors();

    expect(Setting::get('jsonp_enabled'))->toBeTrue();
});
