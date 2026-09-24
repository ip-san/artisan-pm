<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's UserPreference#time_zone: the zone a user sees times in, an
     * IANA identifier (Asia/Tokyo). Empty means the `default_users_time_zone`
     * setting, then the server's zone. Stored times stay UTC.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('time_zone', 64)->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('time_zone');
        });
    }
};
