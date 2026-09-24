<?php

use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\Format\DateTimes;
use App\Support\Format\Hours;
use App\Support\Issues\SubprojectScope;
use App\Support\Pagination\PageSize;
use App\Support\Query\ListDefaults;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * With nothing saved, each setting behaves as Redmine's config/settings.yml
 * default (the 2026-09-24 decision to stop keeping this app's older
 * defaults). A saved value still wins.
 */
test('the settings form starts from Redmine\'s defaults when nothing is saved', function () {
    Livewire::actingAs(User::factory()->admin()->create())->test('settings.index')
        ->assertSet('activity_days_default', 10)
        ->assertSet('mail_handler_allow_override', '')
        ->assertSet('date_format', '')
        ->assertSet('time_format', '')
        ->assertSet('default_users_hide_mail', true)
        ->assertSet('commit_ref_keywords', 'refs,references,IssueID')
        ->assertSet('commit_cross_project_ref', false)
        ->assertSet('commit_fixing_keyword_rules', [])
        ->assertSet('show_custom_fields_on_registration', true)
        ->assertSet('user_format', 'firstname_lastname')
        ->assertSet('display_subprojects_issues', true)
        ->assertSet('timespan_format', 'minutes')
        ->assertSet('issue_list_default_totals', [])
        ->assertSet('webhooks_enabled', false)
        ->assertSet('twofa', '1')
        ->assertSet('default_issue_start_date_to_creation_date', false)
        ->assertSet('default_issue_start_date_for_api_and_mail', true)
        ->assertSet('default_issues_per_page', 25);
});

test('the helpers read Redmine\'s defaults and a saved value wins', function () {
    expect(Hours::timespanFormat())->toBe('minutes')
        ->and(SubprojectScope::enabled())->toBeTrue()
        ->and(ListDefaults::issueTotals())->toBe([])
        ->and(User::factory()->create()->preference('hide_mail'))->toBeTrue()
        ->and(DateTimes::date('2026-09-04'))->toBe('2026/09/04');

    Setting::set('timespan_format', 'decimal');
    Setting::set('display_subprojects_issues', false);
    Setting::set('date_format', '%Y-%m-%d');

    expect(Hours::timespanFormat())->toBe('decimal')
        ->and(SubprojectScope::enabled())->toBeFalse()
        ->and(DateTimes::date('2026-09-04'))->toBe('2026-09-04');
});

test('lists start at the first per-page option', function () {
    expect(PageSize::defaultSize())->toBe(25);

    Setting::set('per_page_options', '10, 30');

    expect(PageSize::defaultSize())->toBe(10);
});

test('the activity page starts 10 days back', function () {
    Carbon::setTestNow('2026-09-25 12:00:00');

    $project = Project::factory()->create();
    $admin = User::factory()->admin()->create();

    expect(Livewire::actingAs($admin)->test('activity.index', ['project' => $project])->get('from'))->toBe('2026-09-15');

    Carbon::setTestNow();
});
