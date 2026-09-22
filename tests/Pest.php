<?php

use App\Models\AuthSource;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Laravel\Testing\EmulatedConnectionFake;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Redmine's own rest_api_enabled default is off (config/settings.yml),
    // matched by this app's Setting::get('rest_api_enabled', false) fallback
    // — but the existing API test suite predates that setting and exercises
    // the API as always-reachable, so Feature tests opt in here by default
    // rather than needing Setting::set('rest_api_enabled', true) added to
    // every one of them individually. Tests that specifically cover the
    // setting's off state set it back to false themselves.
    ->beforeEach(fn () => Setting::set('rest_api_enabled', true))
    // Removes exactly the git repositories createTestGitRepo()/
    // createTestGitRepoWithCommitter() created for this test — never a
    // wildcard directory sweep, since with --parallel that would race
    // another file's own same-prefixed temp directory still in use in a
    // different worker process (this app's own history: see git blame).
    ->afterEach(function () {
        foreach ($GLOBALS['__testGitRepoPaths'] ?? [] as $path) {
            Process::run(['rm', '-rf', $path]);
        }
        $GLOBALS['__testGitRepoPaths'] = [];
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Builds an expected CSV row the same way fputcsv() would (quoting rules
 * vary by PHP version), rather than hand-writing a literal string that
 * could silently drift from actual fputcsv() behavior.
 *
 * @param  array<int, string>  $fields
 */
function csvRow(array $fields, string $separator = ','): string
{
    $handle = fopen('php://memory', 'w+');
    fputcsv($handle, $fields, $separator);
    rewind($handle);
    $row = stream_get_contents($handle);
    fclose($handle);

    return $row;
}

/**
 * Fakes the LDAP directory behind an AuthSource for testing.
 * DirectoryEmulator::setup() replaces an already-registered connection
 * with a fake one, so a stub must be registered under the AuthSource's
 * connection name first — mirroring what LdapAuthenticator itself would
 * register lazily in production on its first real use. Pair with
 * DirectoryEmulator::tearDown() in afterEach().
 */
function fakeAuthSourceDirectory(AuthSource $source): EmulatedConnectionFake
{
    $name = "auth-source-{$source->id}";

    Container::addConnection(new Connection(['base_dn' => $source->base_dn]), $name);

    return DirectoryEmulator::setup($name);
}

/**
 * Shared by every Repository feature test that needs a real git repository
 * to sync from (RepositorySyncTest, CommitKeywordRulesTest, ...). Defined
 * here rather than in one of those test files because Pest's --parallel
 * splits test files across worker processes, and a global function defined
 * in one file isn't available in a worker that never loaded that file.
 *
 * @param  array<int, string>  $commitMessages
 */
function createTestGitRepo(array $commitMessages): string
{
    return createTestGitRepoWithCommitter('Test Committer', 'test@example.com', $commitMessages);
}

/**
 * @param  array<int, string>  $commitMessages
 */
function createTestGitRepoWithCommitter(string $committerName, string $committerEmail, array $commitMessages): string
{
    $path = sys_get_temp_dir().'/scm-test-'.uniqid();
    mkdir($path);
    $GLOBALS['__testGitRepoPaths'][] = $path;

    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();

    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', $committerEmail]);
    $run(['git', 'config', 'user.name', $committerName]);

    foreach ($commitMessages as $i => $message) {
        file_put_contents("{$path}/file{$i}.txt", "content {$i}\n");
        $run(['git', 'add', '-A']);
        $run(['git', 'commit', '-q', '-m', $message]);
    }

    return $path;
}
