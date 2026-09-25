<?php

use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('the project form pre-checks a field that already applies to every project', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $global = CustomField::factory()->create(['name' => 'Global field', 'customized_type' => CustomizableType::Issue->value]);

    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $project])
        ->assertSet('issueCustomFieldIds', [$global->id]);
});

test('the project form only pre-checks an explicitly scoped field for the projects it is linked to', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $scoped = CustomField::factory()->create(['name' => 'Scoped field', 'customized_type' => CustomizableType::Issue->value]);
    $scoped->projects()->attach($other);

    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $project])
        ->assertSet('issueCustomFieldIds', []);

    $scoped->projects()->attach($project);

    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $project])
        ->assertSet('issueCustomFieldIds', [$scoped->id]);
});

test('a project admin can link an explicitly scoped issue custom field to their project through the form', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $scoped = CustomField::factory()->create(['name' => 'Scoped field', 'customized_type' => CustomizableType::Issue->value]);
    $scoped->projects()->attach(Project::factory()->create());

    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $project])
        ->set('issueCustomFieldIds', [$scoped->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($project->issueCustomFields()->pluck('custom_fields.id')->all())->toBe([$scoped->id])
        ->and($scoped->fresh()->appliesToProject($project))->toBeTrue();
});

test('submitting a for-all field id from the project form does not narrow it to only this project', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $global = CustomField::factory()->create(['name' => 'Global field', 'customized_type' => CustomizableType::Issue->value]);

    // A client could still submit a disabled checkbox's value by hand; the
    // server must ignore it rather than write a pivot row that would flip
    // the field from "applies everywhere" to "only here" for every other
    // project too (Project::syncIssueCustomFieldIds()).
    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $project])
        ->set('issueCustomFieldIds', [$global->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($global->fresh())
        ->isForAll()->toBeTrue()
        ->appliesToProject($otherProject)->toBeTrue();
});

test('unchecking a field that is only linked to this project is refused, since it would become for-all everywhere', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $soleLink = CustomField::factory()->create(['name' => 'Sole link field', 'customized_type' => CustomizableType::Issue->value]);
    $soleLink->projects()->attach($project);

    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $project])
        ->assertSet('issueCustomFieldIds', [$soleLink->id])
        ->set('issueCustomFieldIds', [])
        ->call('save')
        ->assertHasErrors(['issueCustomFieldIds']);

    expect($soleLink->fresh())
        ->isForAll()->toBeFalse()
        ->appliesToProject($project)->toBeTrue();
});

test('keeping a sole-link field checked is accepted even though Livewire hydrates checkbox values as strings', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $soleLink = CustomField::factory()->create(['name' => 'Sole link field', 'customized_type' => CustomizableType::Issue->value]);
    $soleLink->projects()->attach($project);

    // A checkbox array bound with wire:model comes back from the browser
    // as strings ("5"), not ints — issueCustomFieldsLosingTheirLastLink()
    // must still recognize the kept id as the same field, not treat it as
    // removed just because "5" !== 5.
    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $project])
        ->set('issueCustomFieldIds', [(string) $soleLink->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($soleLink->fresh())->appliesToProject($project)->toBeTrue();
});

test('unchecking a field that is still linked to another project is allowed', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $multiLink = CustomField::factory()->create(['name' => 'Multi link field', 'customized_type' => CustomizableType::Issue->value]);
    $multiLink->projects()->attach([$project->id, $otherProject->id]);

    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $project])
        ->set('issueCustomFieldIds', [])
        ->call('save')
        ->assertHasNoErrors();

    expect($multiLink->fresh())
        ->appliesToProject($project)->toBeFalse()
        ->appliesToProject($otherProject)->toBeTrue();
});

test('a project custom field never appears in the issue custom field picker', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    CustomField::factory()->create(['name' => 'Project field only', 'customized_type' => CustomizableType::Project->value]);

    Livewire::actingAs($admin)
        ->test('projects.form', ['project' => $project])
        ->assertSet('issueCustomFieldIds', [])
        ->assertDontSeeHtml('課題のカスタムフィールド');
});
