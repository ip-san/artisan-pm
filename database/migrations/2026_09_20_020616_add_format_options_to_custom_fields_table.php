<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Options of a field format that has settings of its own: the roles a
     * `user` field may pick from and the version statuses a `version` field
     * offers (Redmine's user_role / version_status).
     */
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->json('format_options')->nullable()->after('possible_values');
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn('format_options');
        });
    }
};
