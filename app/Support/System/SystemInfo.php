<?php

declare(strict_types=1);

namespace App\Support\System;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What the administrator sees on 管理 → 情報 (Redmine's admin/info): the
 * versions in use and whether the things the app depends on are in place —
 * writable directories, the SCM binaries, image handling, the queue.
 */
final class SystemInfo
{
    /**
     * Directories the application writes to, relative to the base path.
     *
     * @var array<int, string>
     */
    public const array WRITABLE_PATHS = ['storage/app', 'storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'];

    /**
     * @return array<string, string>
     */
    public function versions(): array
    {
        return [
            __('アプリケーション') => (string) config('app.name'),
            'Laravel' => Application::VERSION,
            'PHP' => PHP_VERSION,
            __('データベース') => $this->databaseVersion(),
            'OS' => php_uname('s').' '.php_uname('r'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [
            __('環境 (APP_ENV)') => (string) config('app.env'),
            __('デバッグモード') => config('app.debug') ? __('有効') : __('無効'),
            'APP_URL' => (string) config('app.url'),
            __('タイムゾーン') => (string) config('app.timezone'),
            __('キャッシュ') => (string) config('cache.default'),
            __('セッション') => (string) config('session.driver'),
            __('メール送信') => (string) config('mail.default'),
            __('ファイル保存先') => (string) config('media-library.disk_name'),
        ];
    }

    /**
     * @return array<int, array{name: string, ok: bool, detail: string}>
     */
    public function checks(): array
    {
        $checks = [];

        foreach (self::WRITABLE_PATHS as $relative) {
            $path = base_path($relative);

            $checks[] = [
                'name' => __(':path への書き込み', ['path' => $relative]),
                'ok' => is_dir($path) && is_writable($path),
                'detail' => is_dir($path) ? (is_writable($path) ? __('書き込み可') : __('書き込み不可')) : __('ディレクトリがありません'),
            ];
        }

        foreach (['git' => ['git', '--version'], 'svn' => ['svn', '--version', '--quiet']] as $name => $command) {
            $version = $this->commandOutput($command);

            $checks[] = ['name' => __(':name コマンド', ['name' => $name]), 'ok' => $version !== null, 'detail' => $version ?? __('見つかりません(この種別のリポジトリは使えません)')];
        }

        $checks[] = $this->imageCheck();

        foreach (['mbstring', 'zip', 'pdo_pgsql', 'gd'] as $extension) {
            $checks[] = ['name' => __('PHP拡張 :extension', ['extension' => $extension]), 'ok' => extension_loaded($extension), 'detail' => extension_loaded($extension) ? __('有効') : __('無効')];
        }

        return $checks;
    }

    /**
     * @return array{connection: string, pending: ?int, failed: ?int}
     */
    public function queue(): array
    {
        $connection = (string) config('queue.default');

        return [
            'connection' => $connection,
            'pending' => $connection === 'database' ? $this->countRows((string) config('queue.connections.database.table', 'jobs')) : null,
            'failed' => $this->countRows((string) config('queue.failed.table', 'failed_jobs')),
        ];
    }

    private function databaseVersion(): string
    {
        try {
            $driver = DB::connection()->getDriverName();
            $version = DB::selectOne('select version() as version');

            return $driver.' — '.($version->version ?? __('不明'));
        } catch (Throwable) {
            return __('接続できません');
        }
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function imageCheck(): array
    {
        $magick = $this->commandOutput(['magick', '-version']) ?? $this->commandOutput(['convert', '-version']);

        if ($magick !== null) {
            return ['name' => __('画像処理 (ImageMagick)'), 'ok' => true, 'detail' => strtok($magick, "\n") ?: $magick];
        }

        if (extension_loaded('imagick')) {
            return ['name' => __('画像処理 (ImageMagick)'), 'ok' => true, 'detail' => __('PHP拡張 :extension', ['extension' => 'imagick'])];
        }

        return ['name' => __('画像処理 (ImageMagick)'), 'ok' => extension_loaded('gd'), 'detail' => extension_loaded('gd') ? __('ImageMagick はなく GD で代替') : __('見つかりません(サムネイルは作られません)')];
    }

    /**
     * The first line a command prints, or null when it cannot be run.
     *
     * @param  array<int, string>  $command
     */
    private function commandOutput(array $command): ?string
    {
        try {
            $result = Process::timeout(5)->run($command);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->output()."\n".$result->errorOutput());

        return $output !== '' ? trim(strtok($output, "\n") ?: $output) : __('あり');
    }

    private function countRows(string $table): ?int
    {
        try {
            return Schema::hasTable($table) ? (int) DB::table($table)->count() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
