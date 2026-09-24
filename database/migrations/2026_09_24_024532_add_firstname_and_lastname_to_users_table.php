<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's users.firstname / users.lastname (30 / 255 characters), kept
     * optional beside the existing `name`: existing accounts are not split, and
     * the `user_format` name formats apply only to users with both parts.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('firstname', 30)->nullable()->after('name');
            $table->string('lastname', 255)->nullable()->after('firstname');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['firstname', 'lastname']);
        });
    }
};
