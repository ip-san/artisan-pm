<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Query\IssueFilterFieldRegistry;
use Livewire\Livewire;

/**
 * Three members whose first-name order (Adam, Beth, Carl) is the reverse of
 * their last-name order (Zeller, Young, Xu), plus one without name parts.
 *
 * @return array{project: Project, adam: User, beth: User, carl: User, plain: User}
 */
function sortOrderSetup(): array
{
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues', 'edit_issues', 'add_issue_watchers'], 'assignable' => true]);
    $users = [
        'adam' => User::factory()->create(['firstname' => 'Adam', 'lastname' => 'Zeller', 'login' => 'c-adam']),
        'beth' => User::factory()->create(['firstname' => 'Beth', 'lastname' => 'Young', 'login' => 'b-beth']),
        'carl' => User::factory()->create(['firstname' => 'Carl', 'lastname' => 'Xu', 'login' => 'a-carl']),
        // Named "Yves" with no parts: sorts by name in every format.
        'plain' => User::factory()->create(['name' => 'Yves', 'login' => 'd-yves']),
    ];

    foreach ($users as $user) {
        Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    }

    return ['project' => $project, ...$users];
}

test('users sort by the display format like Redmine\'s fields_for_order_statement', function (string $format, array $expected) {
    $s = sortOrderSetup();
    Setting::set('user_format', $format);

    $ids = fn (array $keys) => array_map(fn (string $key) => $s[$key]->id, $keys);
    $userIds = collect($ids(['adam', 'beth', 'carl', 'plain']));

    expect(User::query()->whereIn('id', $userIds)->sortedByFormat()->pluck('id')->all())->toBe($ids($expected))
        ->and(User::sortByFormat(User::query()->whereIn('id', $userIds)->inRandomOrder()->get())->pluck('id')->all())->toBe($ids($expected));
})->with([
    'firstname_lastname' => ['firstname_lastname', ['adam', 'beth', 'carl', 'plain']],
    'lastname_comma_firstname' => ['lastname_comma_firstname', ['carl', 'beth', 'plain', 'adam']],
    'lastname' => ['lastname', ['carl', 'beth', 'plain', 'adam']],
    'login' => ['login', ['carl', 'beth', 'adam', 'plain']],
]);

test('the assignee and watcher options and the filter choices follow the format order', function () {
    $s = sortOrderSetup();
    Setting::set('user_format', 'lastname_comma_firstname');
    $expected = [$s['carl']->id, $s['beth']->id, $s['plain']->id, $s['adam']->id];

    expect($s['project']->assignableUsers()->pluck('id')->all())->toBe($expected)
        ->and(array_keys(IssueFilterFieldRegistry::forProject($s['project'])->get('author_id')->options()))->toBe($expected);

    $form = Livewire::actingAs($s['adam'])->test('issues.form', ['project' => $s['project']]);

    expect($form->instance()->watcherOptions->pluck('id')->all())->toBe($expected)
        ->and($form->instance()->projectMembers->pluck('id')->all())->toBe($expected);
});
