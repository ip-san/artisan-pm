<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's users.passwd_changed_on (when the password last changed, for
     * the maximum password age) and users.must_change_passwd (an
     * administrator's "change it at next login"). Existing accounts start
     * their password age now, so switching the maximum age on later does not
     * lock everyone out at once.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('passwd_changed_on')->nullable()->after('password');
            $table->boolean('must_change_passwd')->default(false)->after('passwd_changed_on');
        });

        DB::table('users')->update(['passwd_changed_on' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['passwd_changed_on', 'must_change_passwd']);
        });
    }
};
