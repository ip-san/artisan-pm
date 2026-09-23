<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's roles.settings: per-permission tracker restrictions
     * (permissions_all_trackers / permissions_tracker_ids) for the issue
     * permissions. Null or a missing key means "all trackers", so existing
     * roles keep their behaviour without a data migration.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('permissions');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
