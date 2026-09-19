<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's groups.twofa_required: members of such a group must set up
     * two-factor authentication when the site-wide twofa setting is
     * "optional" (1) or "required for administrators" (3).
     */
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('twofa_required')->default(false)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('twofa_required');
        });
    }
};
