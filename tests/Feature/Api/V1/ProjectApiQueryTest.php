<?php

use App\Enums\CustomizableType;
use App\Enums\ProjectStatus;
use App\Models\CustomField;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * A11-18: GET /projects.json takes the project list's filters and
 * limit/offset, and still lists only the projects the caller may see.
 *
 * @return array<int, string>
 */
function projectApiNames(string $query = ''): array
{
    return collect(test()->getJson('/api/v1/projects'.($query !== '' ? "?{$query}" : ''))->assertOk()->json('data'))->pluck('name')->all();
}

test('the project filters narrow the list in both parameter forms', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);
    $alpha = Project::factory()->create(['name' => 'Alpha tools', 'identifier' => 'alpha']);
    Project::factory()->create(['name' => 'Beta site', 'identifier' => 'beta']);
    Project::factory()->create(['name' => 'Gamma tools', 'identifier' => 'gamma', 'parent_id' => $alpha->id]);
    Project::factory()->create(['name' => 'Closed tools', 'identifier' => 'closed-one', 'status' => ProjectStatus::Closed]);

    expect(projectApiNames('f[]=name&op[name]=~&v[name][]=TOOLS'))->toBe(['Alpha tools', 'Closed tools', 'Gamma tools'])
        ->and(projectApiNames('name=^beta'))->toBe(['Beta site'])
        ->and(projectApiNames("parent_id={$alpha->id}"))->toBe(['Gamma tools'])
        ->and(projectApiNames('parent_id=!*'))->toBe(['Alpha tools', 'Beta site', 'Closed tools'])
        ->and(projectApiNames('status=closed'))->toBe(['Closed tools'])
        ->and(projectApiNames('unknown_field=1'))->toBe(['Alpha tools', 'Beta site', 'Closed tools', 'Gamma tools']);
});

test('limit and offset page the list and report the total', function () {
    Passport::actingAs(User::factory()->create());
    foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
        Project::factory()->create(['name' => "Project {$letter}"]);
    }

    $response = $this->getJson('/api/v1/projects?limit=2&offset=2')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Project C', 'Project D'])
        ->and($response->json('total_count'))->toBe(5)
        ->and($response->json('offset'))->toBe(2)
        ->and($response->json('limit'))->toBe(2);
});

test('filters never reach a project the caller cannot see', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);
    $mine = Project::factory()->private()->create(['name' => 'Secret mine']);
    Project::factory()->private()->create(['name' => 'Secret other']);
    Project::factory()->create(['name' => 'Archived public', 'status' => ProjectStatus::Archived]);
    Member::factory()->for($mine)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project']]));

    $response = $this->getJson('/api/v1/projects?name=~secret')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Secret mine'])
        ->and($response->json('total_count'))->toBe(1)
        ->and(projectApiNames('name=~archived'))->toBe([])
        ->and(projectApiNames('status=archived'))->not->toContain('Archived public');
});

test('a role-restricted project custom field cannot be used to filter', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::Project, 'name' => 'Budget', 'is_filter' => true]);
    $field->roles()->attach(Role::factory()->create());
    $rich = Project::factory()->create(['name' => 'Rich']);
    Project::factory()->create(['name' => 'Poor']);
    auth()->setUser(User::factory()->admin()->create());
    $rich->setCustomFieldValues([$field->id => 'big']);
    Passport::actingAs($user);

    expect(projectApiNames("cf_{$field->id}=big"))->toBe(['Poor', 'Rich']);
});
