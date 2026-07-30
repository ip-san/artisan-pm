<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a pre-existing user with no login is backfilled from their email local part', function () {
    // RefreshDatabase always runs migrations against an empty table, so the
    // normal test run never exercises this backfill path — this test drives
    // the migration's up()/down() directly against a row inserted to
    // simulate pre-existing data, bypassing the model's own factory/hooks
    // entirely.
    $migration = require database_path('migrations/2026_07_30_040000_make_login_mandatory_on_users_table.php');

    $migration->down();

    $id = DB::table('users')->insertGetId([
        'name' => 'Pre Existing',
        'email' => 'pre.existing@example.com',
        'password' => bcrypt('secret'),
        'login' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('users')->where('id', $id)->value('login'))->toBe('pre.existing');
});

test('a colliding backfilled login is disambiguated with a numeric suffix', function () {
    $migration = require database_path('migrations/2026_07_30_040000_make_login_mandatory_on_users_table.php');

    $migration->down();

    $existingId = DB::table('users')->insertGetId([
        'name' => 'Existing',
        'email' => 'existing@other-domain.example.com',
        'password' => bcrypt('secret'),
        'login' => 'collide',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $blankId = DB::table('users')->insertGetId([
        'name' => 'Blank Login',
        'email' => 'collide@example.com',
        'password' => bcrypt('secret'),
        'login' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('users')->where('id', $existingId)->value('login'))->toBe('collide')
        ->and(DB::table('users')->where('id', $blankId)->value('login'))->toBe('collide-2');
});

test('a user with an unsanitizable local part falls back to a user-id-based login', function () {
    $migration = require database_path('migrations/2026_07_30_040000_make_login_mandatory_on_users_table.php');

    $migration->down();

    $id = DB::table('users')->insertGetId([
        'name' => 'No Local Part',
        'email' => '@example.com',
        'password' => bcrypt('secret'),
        'login' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('users')->where('id', $id)->value('login'))->toBe('user'.$id);
});

test('the users table rejects a null login after the migration has run', function () {
    expect(fn () => DB::table('users')->insert([
        'name' => 'No Login',
        'email' => 'no-login@example.com',
        'password' => bcrypt('secret'),
        'login' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the users table still rejects a duplicate login after the migration has run', function () {
    // The unique index on login predates this migration (2026_07_21); this
    // asserts it survived the later ->change() call to add NOT NULL rather
    // than assuming Postgres leaves separate index objects alone.
    User::factory()->create(['login' => 'taken']);

    expect(fn () => DB::table('users')->insert([
        'name' => 'Dupe',
        'email' => 'dupe@example.com',
        'password' => bcrypt('secret'),
        'login' => 'taken',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
