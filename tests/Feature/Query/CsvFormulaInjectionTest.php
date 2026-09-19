<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Export\CsvCell;
use Livewire\Livewire;

test('text a spreadsheet would run as a formula is prefixed with an apostrophe', function () {
    expect(CsvCell::safe('=SUM(A1:A9)'))->toBe("'=SUM(A1:A9)")
        ->and(CsvCell::safe('+cmd|calc'))->toBe("'+cmd|calc")
        ->and(CsvCell::safe('-2+3+cmd'))->toBe("'-2+3+cmd")
        ->and(CsvCell::safe('@SUM(1)'))->toBe("'@SUM(1)")
        ->and(CsvCell::safe("\tTabbed"))->toBe("'\tTabbed")
        ->and(CsvCell::safe("\rReturn"))->toBe("'\rReturn");
});

test('ordinary text, numbers and blanks pass through untouched', function () {
    expect(CsvCell::safe('Alice'))->toBe('Alice')
        ->and(CsvCell::safe('2.50'))->toBe('2.50')
        ->and(CsvCell::safe('-5'))->toBe('-5')
        ->and(CsvCell::safe(''))->toBe('')
        ->and(CsvCell::safe(null))->toBe('')
        ->and(CsvCell::safe(12))->toBe('12')
        ->and(CsvCell::safe('a=b'))->toBe('a=b');
});

test('the issue list CSV neutralizes a formula in a subject', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    Issue::factory()->for($project)->create(['subject' => '=HYPERLINK("http://evil.test","x")']);

    $component = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('statusFilter', 'all')->set('columns', ['subject'])->call('exportCsv');

    expect(base64_decode($component->effects['download']['content']))->toContain("'=HYPERLINK")->not->toContain("\n=HYPERLINK");
});

test('the user list CSV neutralizes a formula in a name', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin']);
    User::factory()->create(['name' => '@SUM(1+1)', 'login' => 'sneaky']);

    $component = Livewire::actingAs($admin)->test('users.index')->set('columns', ['name'])->call('exportCsv');

    expect(base64_decode($component->effects['download']['content']))->toContain("'@SUM(1+1)");
});

test('the time report CSV neutralizes a formula in a row label', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create(['name' => '=1+1']);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_time_entries', 'log_time']]));
    App\Models\TimeEntry::factory()->for($project)->create(['user_id' => $user->id, 'hours' => 1, 'spent_on' => '2026-03-10']);

    $component = Livewire::actingAs($user)->test('time-entries.report', ['project' => $project])->set('criteria', ['user'])->call('exportCsv');

    expect(base64_decode($component->effects['download']['content']))->toContain("'=1+1");
});
