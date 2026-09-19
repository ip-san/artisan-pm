<?php

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Laravel\Passport\Passport;
use Livewire\Livewire;

test('an issue custom field exposes description, is_for_all, visible and Redmine-shaped projects, trackers and roles', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create(['name' => 'Bug']);
    $project = Project::factory()->create(['name' => 'Alpha']);
    $role = Role::factory()->create(['name' => 'Developer']);
    $field = CustomField::factory()->create(['name' => 'Severity', 'description' => 'How bad is it']);
    $field->trackers()->attach($tracker);
    $field->projects()->attach($project);
    $field->roles()->attach($role);

    Passport::actingAs($admin);

    $data = $this->getJson('/api/v1/custom_fields')->assertOk()->json('data.0');

    expect($data['description'])->toBe('How bad is it')
        ->and($data['is_for_all'])->toBeFalse()
        ->and($data['visible'])->toBeFalse()
        ->and($data['projects'])->toBe([['id' => $project->id, 'name' => 'Alpha']])
        ->and($data['trackers'])->toBe([['id' => $tracker->id, 'name' => 'Bug']])
        ->and($data['roles'])->toBe([['id' => $role->id, 'name' => 'Developer']])
        ->and($data['tracker_ids'])->toBe([$tracker->id])
        ->and($data['role_ids'])->toBe([$role->id]);
});

test('a field for all projects and visible to every role reports it', function () {
    $admin = User::factory()->admin()->create();
    CustomField::factory()->create(['name' => 'Open']);

    Passport::actingAs($admin);

    $data = $this->getJson('/api/v1/custom_fields')->assertOk()->json('data.0');

    expect($data['is_for_all'])->toBeTrue()
        ->and($data['visible'])->toBeTrue()
        ->and($data['description'])->toBeNull();
});

test('projects and trackers appear only for issue fields and roles only where roles apply', function () {
    $admin = User::factory()->admin()->create();
    CustomField::factory()->create(['name' => 'Group field', 'customized_type' => CustomizableType::Group->value]);
    CustomField::factory()->create(['name' => 'Project field', 'customized_type' => CustomizableType::Project->value]);

    Passport::actingAs($admin);

    $byName = collect($this->getJson('/api/v1/custom_fields')->assertOk()->json('data'))->keyBy('name');

    expect($byName['Group field'])->not->toHaveKeys(['projects', 'trackers', 'roles'])
        ->and($byName['Project field'])->toHaveKey('roles')->not->toHaveKeys(['projects', 'trackers']);
});

test('the index loads its relations without per-field queries', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    foreach (range(1, 6) as $i) {
        $field = CustomField::factory()->create();
        $field->trackers()->attach($tracker);
        $field->projects()->attach(Project::factory()->create());
        $field->roles()->attach(Role::factory()->create());
    }

    Passport::actingAs($admin);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->getJson('/api/v1/custom_fields')->assertOk();

    expect($queries)->toBeLessThan(20);
});

test('the custom field form saves the description and the input shows it as help text', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)->test('custom-fields.form')
        ->set('name', 'Documented')
        ->set('description', 'Enter the ticket reference')
        ->set('customized_type', 'issue')
        ->set('trackerIds', [$tracker->id])
        ->set('field_format', CustomFieldFormat::String->value)
        ->call('save')
        ->assertHasNoErrors();

    $field = CustomField::query()->where('name', 'Documented')->firstOrFail();
    expect($field->description)->toBe('Enter the ticket reference');

    $html = view('components.custom-field-input', ['field' => $field, 'wireModel' => 'customFieldValues', 'errors' => new Illuminate\Support\ViewErrorBag])->render();
    expect($html)->toContain('Enter the ticket reference');

    Livewire::actingAs($admin)->test('custom-fields.form', ['customField' => $field])
        ->assertSet('description', 'Enter the ticket reference')
        ->set('description', '')
        ->call('save');

    expect($field->fresh()->description)->toBeNull();
});
