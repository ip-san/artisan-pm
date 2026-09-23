<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;

/**
 * The views whose strings go through __() with the Japanese original as the
 * key (A14-01b). Add each screen group here as it is translated.
 *
 * @return list<string>
 */
function translatedViews(): array
{
    return [
        'resources/views/components/layouts/app.blade.php',
        ...glob(base_path('resources/views/livewire/issues/*.blade.php')),
        ...glob(base_path('resources/views/livewire/projects/*.blade.php')),
        ...glob(base_path('resources/views/livewire/versions/*.blade.php')),
        ...glob(base_path('resources/views/livewire/issue-categories/*.blade.php')),
        ...glob(base_path('resources/views/components/*.blade.php')),
    ];
}

/**
 * @return array<string, string>
 */
function englishTranslations(): array
{
    return json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The literal keys of every __('…') call in a file.
 *
 * @return list<string>
 */
function translationKeysIn(string $path): array
{
    preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", file_get_contents($path), $matches);

    return array_map(fn (string $key): string => stripslashes($key), $matches[1]);
}

/**
 * @return list<string>
 */
function placeholdersIn(string $text): array
{
    preg_match_all('/:([a-zA-Z_]+)/', $text, $matches);
    $names = array_unique($matches[1]);
    sort($names);

    return $names;
}

test('every translated key in the translated views has an English entry', function () {
    $english = englishTranslations();
    $missing = [];

    foreach (translatedViews() as $view) {
        $path = str_starts_with($view, '/') ? $view : base_path($view);

        foreach (translationKeysIn($path) as $key) {
            if (! array_key_exists($key, $english)) {
                $missing[] = basename($path).': '.$key;
            }
        }
    }

    expect($missing)->toBe([]);
});

test('the English entries keep the placeholders of their Japanese keys', function () {
    $mismatched = collect(englishTranslations())
        ->filter(fn (string $english, string $japanese): bool => placeholdersIn($english) !== placeholdersIn($japanese))
        ->keys()->all();

    expect($mismatched)->toBe([]);
});

test('the Japanese file stays empty because the keys are the Japanese originals', function () {
    expect(json_decode(file_get_contents(lang_path('ja.json')), true))->toBe([]);
});

test('the issue page is shown in the language of the signed-in user', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
    $route = route('issues.show', [$project, $issue]);

    $this->actingAs(User::factory()->admin()->create(['language' => 'en']))->get($route)
        ->assertOk()->assertSee('My page')->assertSee('Watchers')->assertSee('Related issues')
        ->assertDontSee('マイページ')->assertDontSee('関連課題');

    $this->actingAs(User::factory()->admin()->create(['language' => 'ja']))->get($route)
        ->assertOk()->assertSee('マイページ')->assertSee('関連課題')->assertDontSee('Related issues');
});

test('the issue list is shown in English for an English user', function () {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->admin()->create(['language' => 'en']))
        ->get(route('issues.index', $project))
        ->assertOk()->assertSee('My page')->assertDontSee('マイページ');
});

test('the project overview and roadmap are shown in English for an English user', function () {
    $project = Project::factory()->create();
    $english = User::factory()->admin()->create(['language' => 'en']);

    $this->actingAs($english)->get(route('projects.show', $project))
        ->assertOk()->assertSee('Close')->assertDontSee('クローズする');

    $this->actingAs($english)->get(route('versions.roadmap', $project))
        ->assertOk()->assertDontSee('マイページ');
});
