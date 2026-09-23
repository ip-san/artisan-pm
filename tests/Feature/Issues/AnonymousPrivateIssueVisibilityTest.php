<?php

use App\Enums\RoleBuiltin;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

/**
 * A1-38: Redmine's Issue.visible_condition / visible? show a visitor who
 * isn't logged in only public issues, whatever the Anonymous role's
 * issues_visibility.
 *
 * @return array{project: Project, public: Issue, private: Issue}
 */
function anonymousVisibilityFixture(string $issuesVisibility = 'all'): array
{
    Setting::set('login_required', false);

    Role::factory()->create([
        'builtin' => RoleBuiltin::Anonymous->value,
        'issues_visibility' => $issuesVisibility,
        'permissions' => ['view_issues', 'view_calendar', 'view_gantt', 'search_project'],
    ]);

    $project = Project::factory()->create(['is_public' => true]);
    $dates = ['start_date' => now()->toDateString(), 'due_date' => now()->toDateString()];

    $public = Issue::factory()->for($project)->create(['subject' => '公開されている課題ZQX', ...$dates]);
    $private = Issue::factory()->for($project)->create([
        'subject' => '非公開の秘密課題ZQX',
        'is_private' => true,
        'assigned_to_id' => null,
        ...$dates,
    ]);

    return ['project' => $project, 'public' => $public, 'private' => $private];
}

dataset('anonymous issues visibility', ['all', 'default', 'own']);

test('an anonymous visitor never sees a private issue in the scopes or isVisibleTo', function (string $issuesVisibility) {
    ['project' => $project, 'public' => $public, 'private' => $private] = anonymousVisibilityFixture($issuesVisibility);

    expect(Issue::query()->visibleTo(null, $project)->pluck('id')->all())->toBe([$public->id])
        ->and(Issue::query()->visibleToAcrossProjects(null, collect([$project]))->pluck('id')->all())->toBe([$public->id])
        ->and(Issue::query()->visible(null)->pluck('id')->all())->toBe([$public->id])
        ->and($public->isVisibleTo(null))->toBeTrue()
        ->and($private->fresh()->isVisibleTo(null))->toBeFalse()
        ->and(Issue::filterVisible(collect([$public, $private]), null)->pluck('id')->all())->toBe([$public->id]);
})->with('anonymous issues visibility');

test('the anonymous tier collapses to public issues over the tiers\' trackers', function () {
    ['project' => $project] = anonymousVisibilityFixture('own');

    expect(app(AuthorizationService::class)->issueVisibilityRules(null, $project))->toBe(['default' => null]);
});

test('a signed-in non-member with the all tier still sees private issues', function () {
    ['project' => $project, 'private' => $private] = anonymousVisibilityFixture();
    Role::factory()->create(['builtin' => RoleBuiltin::NonMember->value, 'issues_visibility' => 'all', 'permissions' => ['view_issues']]);

    expect($private->fresh()->isVisibleTo(User::factory()->create()))->toBeTrue();
});

test('an anonymous visitor does not see a private issue on any web page or feed', function (string $issuesVisibility) {
    ['project' => $project, 'private' => $private] = anonymousVisibilityFixture($issuesVisibility);

    $pages = [
        route('issues.index', $project),
        route('issues.global-index'),
        route('issues.atom', $project),
        route('issues.changes-atom', $project),
        route('issues.global-changes-atom'),
        route('calendar.index', $project),
        route('calendar.global-index'),
        route('gantt.index', $project),
        route('search.index', ['project' => $project, 'query' => 'ZQX']),
        route('search.global-index', ['query' => 'ZQX']),
        route('activity.index', $project),
        route('activity.global-index'),
        route('activity.atom', $project),
        route('activity.global-atom'),
    ];

    $servedToGuests = [];

    foreach ($pages as $url) {
        $response = $this->get($url);

        // Pages that need a login send the visitor there and leak nothing.
        if ($response->isRedirect(route('login'))) {
            continue;
        }

        expect($response->status())->toBe(200, $url);
        $response->assertDontSee('非公開の秘密課題ZQX');
        $servedToGuests[] = $url;
    }

    expect($servedToGuests)->toContain(route('issues.index', $project));

    $this->get(route('issues.show', [$project, $private]))->assertForbidden();
    expect($this->get(route('issues.pdf', [$project, $private]))->status())->toBeIn([302, 403]);
})->with('anonymous issues visibility');

test('an anonymous visitor sees the public issue on the same pages', function () {
    ['project' => $project, 'public' => $public] = anonymousVisibilityFixture();

    $this->get(route('issues.index', $project))->assertSee('公開されている課題ZQX');
    $this->get(route('issues.show', [$project, $public]))->assertOk();
});

test('the REST API without a key refuses to list or show issues', function () {
    ['project' => $project, 'private' => $private] = anonymousVisibilityFixture();
    Setting::set('rest_api_enabled', true);

    $this->getJson(route('api.issues.index', $project))->assertUnauthorized();
    $this->getJson(route('api.issues.global_index'))->assertUnauthorized();
    $this->getJson(route('api.issues.show', $private))->assertUnauthorized();
    $this->getJson(route('api.search.index', ['q' => 'ZQX']))->assertUnauthorized();
});
