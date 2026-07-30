<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Matches Redmine's own default_users_no_self_notified setting
            // default (1/true, config/settings.yml) — a fresh install
            // notifies users about everything except their own changes.
            $table->boolean('no_self_notified')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('no_self_notified');
        });
    }
};
