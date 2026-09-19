<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Query\IssueFilterFieldRegistry;
use Laravel\Passport\Passport;
use Livewire\Livewire;

function isFilterProject(): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    return [$project, $tracker];
}

test('a custom field is a filter by default, keeping the behaviour every existing field had', function () {
    expect((new CustomField)->is_filter)->toBeTrue()
        ->and(CustomField::factory()->create()->is_filter)->toBeTrue();
});

test('the project filter registry offers a filterable field and omits one that is not', function () {
    [$project, $tracker] = isFilterProject();
    $filterable = CustomField::factory()->create(['name' => 'Yes filter', 'is_filter' => true]);
    $plain = CustomField::factory()->create(['name' => 'No filter', 'is_filter' => false]);
    $filterable->trackers()->attach($tracker);
    $plain->trackers()->attach($tracker);

    $fields = IssueFilterFieldRegistry::forProject($project->fresh());

    expect($fields->has("cf_{$filterable->id}"))->toBeTrue()
        ->and($fields->has("cf_{$plain->id}"))->toBeFalse();
});

test('the cross-project registry applies the same rule', function () {
    [$project, $tracker] = isFilterProject();
    $plain = CustomField::factory()->create(['is_filter' => false]);
    $plain->trackers()->attach($tracker);

    expect(IssueFilterFieldRegistry::forProjects(collect([$project->fresh()]))->has("cf_{$plain->id}"))->toBeFalse();
});

test('a non-filter field is still a selectable column and keeps its stored values', function () {
    [$project, $tracker] = isFilterProject();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    $field = CustomField::factory()->create(['name' => 'Display only', 'is_filter' => false]);
    $field->trackers()->attach($tracker);

    $component = Livewire::actingAs($user)->test('issues.index', ['project' => $project]);

    expect($component->get('availableColumns'))->toHaveKey("cf_{$field->id}");
});

test('a saved filter on a field that stopped being a filter is ignored instead of failing', function () {
    [$project, $tracker] = isFilterProject();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    $field = CustomField::factory()->create(['is_filter' => false]);
    $field->trackers()->attach($tracker);

    Livewire::actingAs($user)
        ->withQueryParams([
            'activeFilterKeys' => ["cf_{$field->id}"],
            'filterOperators' => ["cf_{$field->id}" => '='],
            'filterValues' => ["cf_{$field->id}" => ['x']],
        ])
        ->test('issues.index', ['project' => $project])
        ->assertOk();
});

test('the custom field form saves the flag', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)->test('custom-fields.form')
        ->assertSet('is_filter', true)
        ->set('name', 'Not for filtering')
        ->set('customized_type', 'issue')
        ->set('trackerIds', [$tracker->id])
        ->set('field_format', CustomFieldFormat::String->value)
        ->set('is_filter', false)
        ->call('save')
        ->assertHasNoErrors();

    $field = CustomField::query()->where('name', 'Not for filtering')->firstOrFail();
    expect($field->is_filter)->toBeFalse();

    Livewire::actingAs($admin)->test('custom-fields.form', ['customField' => $field])
        ->assertSet('is_filter', false)
        ->set('is_filter', true)
        ->call('save');

    expect($field->fresh()->is_filter)->toBeTrue();
});

test('the API exposes is_filter', function () {
    $admin = User::factory()->admin()->create();
    CustomField::factory()->create(['name' => 'Exposed', 'is_filter' => false]);

    Passport::actingAs($admin);

    $this->getJson('/api/v1/custom_fields')->assertOk()->assertJsonPath('data.0.is_filter', false);
});
