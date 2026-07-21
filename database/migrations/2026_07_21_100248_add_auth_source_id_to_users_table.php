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
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('auth_source_id')->nullable()->after('id')->constrained()->nullOnDelete();
            // The value an LDAP-linked user authenticates with (their
            // directory uid, not necessarily their email) — the submitted
            // login on a later attempt won't generally match this user's
            // `email` column, since that's populated from the directory's
            // mail attribute instead. Unused for local-password accounts.
            $table->string('login')->nullable()->unique()->after('auth_source_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('auth_source_id');
            $table->dropColumn('login');
        });
    }
};
