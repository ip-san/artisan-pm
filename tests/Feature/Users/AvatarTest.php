<?php

use App\Models\Issue;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Avatar\UserAvatar;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

test('initials come from the first and last word or the first letters', function () {
    expect(UserAvatar::initials('Jean-Philippe Lang'))->toBe('JL')
        ->and(UserAvatar::initials('  ada   king  lovelace '))->toBe('AL')
        ->and(UserAvatar::initials('madonna'))->toBe('MA')
        ->and(UserAvatar::initials('山田 太郎'))->toBe('山太')
        ->and(UserAvatar::initials('日本語'))->toBe('日')
        ->and(UserAvatar::initials(''))->toBe('?');
});

test('the colour is stable per name and differs between people', function () {
    expect(UserAvatar::color('Alice'))->toBe(UserAvatar::color(' alice '))
        ->and(UserAvatar::color('Alice'))->not->toBe(UserAvatar::color('Bob'))
        ->and(UserAvatar::color('Alice'))->toMatch('/^hsl\(\d{1,3}, 45%, 42%\)$/');
});

test('the Gravatar URL hashes the trimmed lowercase address and asks for double size', function () {
    Setting::set('gravatar_default', 'retro');

    expect(UserAvatar::gravatarUrl('  Someone@Example.COM ', 24))
        ->toBe('https://www.gravatar.com/avatar/'.md5('someone@example.com').'?s=48&d=retro');

    Setting::set('gravatar_default', '');
    expect(UserAvatar::gravatarUrl('a@example.com', 24))->toBe('https://www.gravatar.com/avatar/'.md5('a@example.com').'?s=48');

    Setting::set('gravatar_default', 'initials');
    expect(UserAvatar::gravatarUrl('a@example.com', 24))->toContain('d=404');

    Setting::set('gravatar_default', 'unknown-style');
    expect(UserAvatar::defaultStyle())->toBe('identicon');
});

test('without Gravatar the component draws initials and contacts nobody', function () {
    $user = User::factory()->create(['name' => 'Grace Hopper']);

    $html = Blade::render('<x-avatar :user="$user" :size="30" />', ['user' => $user]);

    expect($html)->toContain('data-avatar')->toContain('GH')->not->toContain('gravatar.com')->not->toContain('<img');
});

test('with Gravatar the component shows the image, and the initials circle only for the initials fallback', function () {
    $user = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
    Setting::set('gravatar_enabled', true);
    Setting::set('gravatar_default', 'identicon');

    $plain = Blade::render('<x-avatar :user="$user" />', ['user' => $user]);
    expect($plain)->toContain(md5('grace@example.com'))->toContain('referrerpolicy="no-referrer"')->not->toContain('>GH<');

    Setting::set('gravatar_default', 'initials');
    $withFallback = Blade::render('<x-avatar :user="$user" />', ['user' => $user]);
    expect($withFallback)->toContain(md5('grace@example.com'))->toContain('GH');
});

test('a missing user renders nothing', function () {
    expect(trim(Blade::render('<x-avatar :user="null" />')))->toBe('');
});

test('the avatar shows beside journal authors on the issue page and on the member list', function () {
    $project = Project::factory()->create();
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'manage_members']])
    );
    $author = User::factory()->create(['name' => 'Katherine Johnson']);
    $issue = Issue::factory()->for($project)->create(['author_id' => $author->id]);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'Hello']);

    expect(Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->html())->toContain('data-avatar')->toContain('KJ');
    expect(Livewire::actingAs($viewer)->test('projects.members', ['project' => $project])->html())->toContain('data-avatar');
});

test('the settings form saves the Gravatar options and rejects an unknown style', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('gravatar_enabled', true)->set('gravatar_default', 'wavatar')->call('save')->assertHasNoErrors();
    expect(Setting::get('gravatar_enabled'))->toBeTrue()->and(Setting::get('gravatar_default'))->toBe('wavatar');

    Livewire::actingAs($admin)->test('settings.index')->set('gravatar_default', '')->call('save')->assertHasNoErrors();
    expect(Setting::get('gravatar_default'))->toBe('');

    Livewire::actingAs($admin)->test('settings.index')->set('gravatar_default', 'nonsense')->call('save')->assertHasErrors(['gravatar_default']);
});
