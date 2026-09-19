<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's per-user Atom key (its `rss_key` token): lets a feed reader,
     * which cannot log in, read the feeds its owner may see.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('atom_key', 40)->nullable()->unique()->after('api_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('atom_key');
        });
    }
};
