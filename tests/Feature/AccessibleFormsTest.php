<?php

use App\Models\Project;
use App\Models\User;

/**
 * Every <label for> must point at an element on the page, and ids must be unique, or screen
 * readers announce fields without their names (axe "label").
 */
function assertLabelsPointAtFields(string $html): void
{
    preg_match_all('/<label[^>]*\sfor="([^"]+)"/', $html, $labels);
    preg_match_all('/\sid="([^"]+)"/', $html, $ids);

    expect($labels[1])->not->toBeEmpty()
        ->and(array_diff($labels[1], $ids[1]))->toBe([])
        ->and(array_diff_assoc($ids[1], array_unique($ids[1])))->toBe([]);
}

test('form labels point at their fields', function (string $routeName) {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();

    $html = $this->actingAs($admin)->get(route($routeName, str_starts_with($routeName, 'issues.') ? $project : []))->assertOk()->getContent();

    assertLabelsPointAtFields($html);
})->with(['issues.create', 'settings.index', 'profile.index', 'projects.create', 'custom-fields.create', 'roles.create']);
