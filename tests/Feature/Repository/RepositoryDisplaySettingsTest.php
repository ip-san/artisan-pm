<?php

use App\Models\Changeset;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Setting;
use App\Models\User;
use App\Services\RepositorySyncService;
use App\Support\Scm\CodesetConverter;
use App\Support\Scm\DisplayLimits;
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

test('a diff longer than diff_max_lines_displayed is cut and says so', function () {
    Setting::set('diff_max_lines_displayed', 3);

    expect(DisplayLimits::truncateDiff("a\nb\nc\nd\ne"))->toBe(['text' => "a\nb\nc", 'truncated' => true])
        ->and(DisplayLimits::truncateDiff("a\nb\nc"))->toBe(['text' => "a\nb\nc", 'truncated' => false]);

    Setting::set('diff_max_lines_displayed', 0);
    expect(DisplayLimits::truncateDiff(str_repeat("x\n", 5000))['truncated'])->toBeFalse();
});

test('file_max_size_displayed hides large files and 0 lifts the limit', function () {
    Setting::set('file_max_size_displayed', 1);

    expect(DisplayLimits::fileTooLargeToDisplay(1024))->toBeFalse()
        ->and(DisplayLimits::fileTooLargeToDisplay(1025))->toBeTrue();

    Setting::set('file_max_size_displayed', 0);
    expect(DisplayLimits::fileTooLargeToDisplay(50_000_000))->toBeFalse();
});

test('the changeset page shows the truncation notice with a small diff limit', function () {
    Setting::set('diff_max_lines_displayed', 2);
    $project = Project::factory()->create();
    $path = sys_get_temp_dir().'/scm-test-'.uniqid();
    mkdir($path);
    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();
    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'dev@example.com']);
    $run(['git', 'config', 'user.name', 'Dev']);
    file_put_contents("{$path}/big.txt", implode("\n", range(1, 40))."\n");
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Big change']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);
    app(RepositorySyncService::class)->sync($repository);
    $changeset = $repository->changesets()->with('repository.project')->orderBy('id')->get()->last();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->assertSee('差分が大きいため');

    Process::path(sys_get_temp_dir())->run(['rm', '-rf', $path]);
});

test('the settings form saves the display limits and thumbnail size', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->set('diff_max_lines_displayed', 200)
        ->set('file_max_size_displayed', 64)
        ->set('thumbnails_size', 150)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('diff_max_lines_displayed'))->toBe(200)
        ->and(Setting::get('file_max_size_displayed'))->toBe(64)
        ->and(Setting::get('thumbnails_size'))->toBe(150);

    Livewire::actingAs($admin)->test('settings.index')->set('thumbnails_size', 4)->call('save')->assertHasErrors(['thumbnails_size']);
});
