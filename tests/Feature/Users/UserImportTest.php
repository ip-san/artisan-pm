<?php

use App\Enums\ImportStatus;
use App\Enums\UserStatus;
use App\Models\AuthSource;
use App\Models\CustomField;
use App\Models\User;
use App\Models\UserImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function userImportRun(string $csv, array $mapping = [], ?User $admin = null): UserImport
{
    Storage::fake('local');
    $admin ??= User::factory()->admin()->create();

    $defaults = ['mapping.login' => 'login', 'mapping.name' => 'name', 'mapping.email' => 'email', 'mapping.password' => 'password'];

    $component = Livewire::actingAs($admin)->test('users.import')->set('csvFile', UploadedFile::fake()->createWithContent('users.csv', $csv));

    foreach ([...$defaults, ...$mapping] as $key => $value) {
        $component->set($key, $value);
    }

    $component->call('startImport')->assertHasNoErrors();

    return UserImport::query()->latest('id')->firstOrFail();
}

test('only administrators can open the user import', function () {
    Livewire::actingAs(User::factory()->create())->test('users.import')->assertForbidden();
});

test('uploading a csv detects the headers and pre-maps the matching columns', function () {
    Storage::fake('local');

    $component = Livewire::actingAs(User::factory()->admin()->create())->test('users.import')
        ->set('csvFile', UploadedFile::fake()->createWithContent('users.csv', "login,name,email,password\nalice,Alice,a@example.com,pw\n"));

    expect($component->get('headers'))->toBe(['login', 'name', 'email', 'password'])
        ->and($component->get('mapping')['login'])->toBe('login')
        ->and($component->get('mapping')['email'])->toBe('email');
});

test('the import creates accounts with the given password, status and admin flag', function () {
    $csv = "login,name,email,password,status,admin\nalice,Alice,alice@example.com,a-strong-password-1,active,\nbob,Bob,bob@example.com,another-strong-pw-2,ロック中,yes\n";

    $import = userImportRun($csv, ['mapping.status' => 'status', 'mapping.admin' => 'admin']);

    $alice = User::where('login', 'alice')->firstOrFail();
    $bob = User::where('login', 'bob')->firstOrFail();

    expect($import->status)->toBe(ImportStatus::Completed)->and($import->imported_count)->toBe(2)->and($import->failed_count)->toBe(0)
        ->and(Hash::check('a-strong-password-1', $alice->password))->toBeTrue()
        ->and($alice->status)->toBe(UserStatus::Active)->and($alice->is_admin)->toBeFalse()
        ->and($bob->status)->toBe(UserStatus::Locked)->and($bob->is_admin)->toBeTrue();
});

test('a bad row is recorded and the rest still imports', function () {
    $csv = "login,name,email,password\ngood,Good,good@example.com,a-strong-password-1\nbad login!,Bad,bad@example.com,a-strong-password-1\n,Nameless,none@example.com,a-strong-password-1\ndupe,Dupe,GOOD@example.com,a-strong-password-1\nweak,Weak,weak@example.com,123\n";

    $import = userImportRun($csv);

    expect($import->imported_count)->toBe(1)->and($import->failed_count)->toBe(4)
        ->and(collect($import->errors)->pluck('row')->all())->toBe([3, 4, 5, 6])
        ->and(User::where('login', 'good')->exists())->toBeTrue()
        ->and(User::where('email', 'bad@example.com')->exists())->toBeFalse();
});

test('a duplicate login is rejected case-insensitively, even within one file', function () {
    User::factory()->create(['login' => 'Taken']);
    $csv = "login,name,email,password\ntaken,A,a@example.com,a-strong-password-1\nfresh,B,b@example.com,a-strong-password-1\nFRESH,C,c@example.com,a-strong-password-1\n";

    $import = userImportRun($csv);

    expect($import->imported_count)->toBe(1)->and($import->failed_count)->toBe(2);
});

test('a row without a password fails unless it names an authentication source', function () {
    $source = AuthSource::factory()->create(['name' => 'Corp LDAP']);
    $csv = "login,name,email,password,auth\nlocal,Local,local@example.com,,\nldap,Ldap,ldap@example.com,,Corp LDAP\n";

    $import = userImportRun($csv, ['mapping.auth_source' => 'auth']);

    $ldap = User::where('login', 'ldap')->firstOrFail();
    expect($import->imported_count)->toBe(1)->and($import->failed_count)->toBe(1)
        ->and($ldap->auth_source_id)->toBe($source->id)
        ->and(User::where('login', 'local')->exists())->toBeFalse();
});

test('an unknown status is a row error, not a silent default', function () {
    $csv = "login,name,email,password,status\nx,X,x@example.com,a-strong-password-1,archived\n";

    $import = userImportRun($csv, ['mapping.status' => 'status']);

    expect($import->failed_count)->toBe(1)->and(User::where('login', 'x')->exists())->toBeFalse();
});

test('a quoted field with a line break and a byte-order mark are read correctly', function () {
    $csv = "\xEF\xBB\xBFlogin,name,email,password\nmulti,\"Multi\nLine\",multi@example.com,a-strong-password-1\n";

    $import = userImportRun($csv);

    expect($import->imported_count)->toBe(1)->and(User::where('login', 'multi')->firstOrFail()->name)->toBe("Multi\nLine");
});

test('user custom fields can be mapped and are validated', function () {
    $field = CustomField::factory()->create(['customized_type' => 'user', 'name' => 'Department', 'is_required' => true]);
    $csv = "login,name,email,password,dept\nwith,With,with@example.com,a-strong-password-1,Sales\nwithout,Without,without@example.com,a-strong-password-1,\n";

    $import = userImportRun($csv, ["mapping.cf_{$field->id}" => 'dept']);

    expect($import->imported_count)->toBe(1)->and($import->failed_count)->toBe(1)
        ->and(User::where('login', 'with')->firstOrFail()->customValue($field))->toBe('Sales');
});

test('the mapping needs login, name and email', function () {
    Storage::fake('local');

    Livewire::actingAs(User::factory()->admin()->create())->test('users.import')
        ->set('csvFile', UploadedFile::fake()->createWithContent('users.csv', "a,b\n1,2\n"))
        ->set('mapping.login', '')->set('mapping.name', '')->set('mapping.email', '')
        ->call('startImport')->assertHasErrors(['mapping.login', 'mapping.name', 'mapping.email']);
});

test('the status page shows the outcome to an administrator only', function () {
    $import = userImportRun("login,name,email,password\nz,Z,z@example.com,a-strong-password-1\n");

    Livewire::actingAs(User::factory()->admin()->create())->test('users.import-status', ['import' => $import])->assertSee('成功 1 件');
    Livewire::actingAs(User::factory()->create())->test('users.import-status', ['import' => $import])->assertForbidden();
});
