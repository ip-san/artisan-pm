<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matches Redmine's User::LOGIN_LENGTH_LIMIT (60) and login format
     * validation (`/\A[a-z0-9_\-@.]*\z/i`, user.rb).
     */
    private const LOGIN_LENGTH_LIMIT = 60;

    public function up(): void
    {
        $used = DB::table('users')->whereNotNull('login')->where('login', '!=', '')
            ->pluck('login')
            ->map(fn (string $login) => strtolower($login))
            ->flip();

        DB::table('users')->whereNull('login')->orWhere('login', '')->orderBy('id')->get()->each(function (object $user) use (&$used): void {
            $localPart = strtolower((string) strstr((string) $user->email, '@', true));
            $sanitized = preg_replace('/[^a-z0-9_\-@.]/', '-', $localPart) ?? '';
            $sanitized = trim($sanitized, '-');
            $base = $sanitized !== '' ? substr($sanitized, 0, self::LOGIN_LENGTH_LIMIT) : 'user'.$user->id;

            $candidate = $base;
            $suffix = 1;
            while (isset($used[strtolower($candidate)])) {
                $suffix++;
                $candidate = substr($base, 0, self::LOGIN_LENGTH_LIMIT - strlen((string) $suffix) - 1).'-'.$suffix;
            }

            $used[strtolower($candidate)] = true;
            DB::table('users')->where('id', $user->id)->update(['login' => $candidate]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('login')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('login')->nullable()->change();
        });
    }
};
