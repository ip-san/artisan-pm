<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\System\SchedulerHeartbeat;
use App\Support\System\SystemInfo;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('only an administrator can open the information page', function () {
    $project = Project::factory()->create();
    $member = User::factory()->create();
    Member::factory()->for($project)->for($member)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project']]));

    $this->get(route('admin.info'))->assertRedirect(route('login'));
    Livewire::actingAs($member)->test('admin.info')->assertForbidden();
    Livewire::actingAs(User::factory()->admin()->create())->test('admin.info')->assertOk();
});

test('the page shows the versions in use', function () {
    $page = Livewire::actingAs(User::factory()->admin()->create())->test('admin.info');

    $page->assertSee(Application::VERSION)->assertSee(PHP_VERSION)->assertSee(DB::connection()->getDriverName());
});

test('versions and environment describe this installation', function () {
    $info = new SystemInfo;

    expect($info->versions())->toHaveKeys(['Laravel', 'PHP', 'データベース'])
        ->and($info->versions()['PHP'])->toBe(PHP_VERSION)
        ->and($info->environment()['環境 (APP_ENV)'])->toBe((string) config('app.env'));
});

test('the checklist covers the writable directories, the SCM binaries, images and extensions', function () {
    $checks = collect((new SystemInfo)->checks())->keyBy('name');

    expect($checks->keys()->all())->toContain('storage/logs への書き込み', 'git コマンド', 'svn コマンド', '画像処理 (ImageMagick)', 'PHP拡張 mbstring')
        ->and($checks['storage/logs への書き込み']['ok'])->toBeTrue()
        ->and($checks['PHP拡張 mbstring']['ok'])->toBeTrue();
});

test('a missing binary is reported as such without breaking the page', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'not found', exitCode: 127)]);

    $checks = collect((new SystemInfo)->checks())->keyBy('name');

    expect($checks['git コマンド']['ok'])->toBeFalse()
        ->and($checks['git コマンド']['detail'])->toContain('見つかりません');

    Livewire::actingAs(User::factory()->admin()->create())->test('admin.info')->assertSee('✘')->assertOk();
});

test('a binary that runs reports its first line of output', function () {
    Process::fake(['*' => Process::result(output: "git version 2.43.0\nextra line")]);

    $git = collect((new SystemInfo)->checks())->firstWhere('name', 'git コマンド');

    expect($git)->toMatchArray(['ok' => true, 'detail' => 'git version 2.43.0']);
});

test('the queue block reports the connection and the failed jobs', function () {
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);

    $queue = (new SystemInfo)->queue();

    expect($queue['connection'])->toBe((string) config('queue.default'))->and($queue['failed'])->toBe(1);
});

test('the navigation links administrators to the page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('projects.index'))->assertSee(route('admin.info'), false);
});

test('the checklist names the PDO extension of the configured database and intl', function (string $connection, string $extension) {
    $default = config('database.default');
    config(['database.default' => $connection]);

    try {
        $names = collect((new SystemInfo)->checks())->pluck('name');
    } finally {
        config(['database.default' => $default]);
    }

    expect($names)->toContain("PHP拡張 {$extension}", 'PHP拡張 intl')
        ->and($names->filter(fn (string $name) => str_starts_with($name, 'PHP拡張 pdo_'))->values()->all())->toBe(["PHP拡張 {$extension}"]);
})->with([
    'pgsql' => ['pgsql', 'pdo_pgsql'],
    'mysql' => ['mysql', 'pdo_mysql'],
    'mariadb' => ['mariadb', 'pdo_mysql'],
    'sqlite' => ['sqlite', 'pdo_sqlite'],
]);

test('schedule:run records the scheduler heartbeat', function () {
    Queue::fake();
    $this->travelTo(now()->startOfMinute());

    expect(SchedulerHeartbeat::lastRun())->toBeNull();

    $this->artisan('schedule:run')->assertSuccessful();

    expect(SchedulerHeartbeat::lastRun()?->toIso8601String())->toBe(now()->toIso8601String())
        ->and(SchedulerHeartbeat::status())->toBe('ok');
});

test('the information page reports a scheduler that never ran, stopped or runs', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.info'))->assertOk()->assertSee('記録なし')->assertSee('schedule:run');

    SchedulerHeartbeat::record();
    $this->travel(10)->minutes();
    $this->actingAs($admin)->get(route('admin.info'))->assertSee('スケジューラが5分以上実行されていません。');

    SchedulerHeartbeat::record();
    $this->actingAs($admin)->get(route('admin.info'))->assertDontSee('記録なし')->assertDontSee('実行されていません');
});
