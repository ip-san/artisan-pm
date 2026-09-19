<?php

use App\Models\Changeset;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Setting;
use App\Models\User;
use App\Services\RepositorySyncService;
use App\Support\Scm\CodesetConverter;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

function sjisText(string $utf8): string
{
    return mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
}

test('text that is already UTF-8 passes through untouched', function () {
    expect(CodesetConverter::toUtf8('日本語 text'))->toBe('日本語 text')
        ->and(CodesetConverter::toUtf8(''))->toBe('');
});

test('legacy bytes are converted with the configured encodings, tried in order', function () {
    Setting::set('repositories_encodings', 'UTF-8, SJIS-win');

    expect(CodesetConverter::toUtf8(sjisText('日本語のコメント')))->toBe('日本語のコメント');
});

test('without a matching encoding invalid bytes are replaced instead of breaking the page', function () {
    $garbled = CodesetConverter::toUtf8(sjisText('日本語'));

    expect(mb_check_encoding($garbled, 'UTF-8'))->toBeTrue();
});

test('an unknown encoding name in the setting is skipped rather than failing', function () {
    Setting::set('repositories_encodings', 'NOT-AN-ENCODING, SJIS-win');

    expect(CodesetConverter::toUtf8(sjisText('日本語')))->toBe('日本語');
});

test('strict conversion tells legacy text from binary content', function () {
    Setting::set('repositories_encodings', 'SJIS-win');

    expect(CodesetConverter::convertStrictly(sjisText('設定ファイル')))->toBe('設定ファイル')
        ->and(CodesetConverter::convertStrictly("\x89PNG\r\n\x1a\n\0\0\0\rIHDR"))->toBeNull()
        ->and(CodesetConverter::convertStrictly("\xff\xfe\xfa"))->toBeNull();
});

test('commit messages in the configured log encoding are stored as UTF-8', function () {
    Setting::set('commit_logs_encoding', 'SJIS-win');
    $project = Project::factory()->create();
    $path = sys_get_temp_dir().'/scm-test-'.uniqid();
    mkdir($path);
    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();
    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'dev@example.com']);
    $run(['git', 'config', 'user.name', 'Dev']);
    $run(['git', 'config', 'i18n.commitEncoding', 'SJIS-win']);
    file_put_contents("{$path}/a.txt", 'a');
    $run(['git', 'add', '-A']);
    file_put_contents("{$path}/message.txt", sjisText('日本語のコミット'));
    $run(['git', 'commit', '-q', '-F', 'message.txt']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    expect($repository->changesets()->firstOrFail()->comments)->toBe('日本語のコミット');
    Process::path(sys_get_temp_dir())->run(['rm', '-rf', $path]);
});

test('the repository history lists at most repository_log_display_limit revisions', function () {
    Setting::set('repository_log_display_limit', 3);
    $project = Project::factory()->create();
    $repository = Repository::factory()->for($project)->create();
    Changeset::factory(6)->for($repository)->create();

    $list = Livewire::actingAs(User::factory()->admin()->create())->test('repository.index', ['project' => $project]);

    expect($list->get('changesets'))->toHaveCount(3);

    Setting::set('repository_log_display_limit', 0);
    expect(Livewire::actingAs(User::factory()->admin()->create())->test('repository.index', ['project' => $project])->get('changesets'))->toHaveCount(6);
});

test('commit messages are rendered as Markdown by default and shown as plain text when formatting is off', function () {
    $project = Project::factory()->create();
    $repository = Repository::factory()->for($project)->create();
    $changeset = Changeset::factory()->for($repository)->create(['comments' => "Fix **loud** bug <script>alert(1)</script>\nsecond line"]);

    $formatted = (string) $changeset->commentsHtml();
    expect($formatted)->toContain('<strong>loud</strong>')->not->toContain('<script>');

    Setting::set('commit_logs_formatting', false);
    $plain = (string) $changeset->commentsHtml();
    expect($plain)->toContain('**loud**')->toContain('&lt;script&gt;')->not->toContain('<strong>');

    expect((string) $changeset->commentsHtml(firstLineOnly: true))->not->toContain('second line');
});

test('the settings form saves the repository display options and rejects unknown encodings', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->set('repository_log_display_limit', 50)
        ->set('repositories_encodings', 'SJIS-win, EUC-JP')
        ->set('commit_logs_encoding', 'EUC-JP')
        ->set('commit_logs_formatting', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('repository_log_display_limit'))->toBe(50)
        ->and(Setting::get('repositories_encodings'))->toBe('SJIS-win, EUC-JP')
        ->and(Setting::get('commit_logs_formatting'))->toBeFalse();

    Livewire::actingAs($admin)->test('settings.index')
        ->set('repositories_encodings', 'SJIS-win, KLINGON')
        ->set('commit_logs_encoding', 'NOPE')
        ->set('repository_log_display_limit', 0)
        ->call('save')
        ->assertHasErrors(['repositories_encodings', 'commit_logs_encoding', 'repository_log_display_limit']);
});
