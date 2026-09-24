<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's repositories.url/login/password (A10-01b): a Subversion
     * repository may live on a remote, allow-listed server instead of under
     * scm.repositories_root. A remote repository has a url and no path, so
     * path becomes nullable. password holds an `encrypted` cast payload,
     * which is longer than the plain value — hence text.
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->string('path')->nullable()->change();
            $table->string('url')->nullable();
            $table->string('login', 60)->nullable();
            $table->text('password')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['url', 'login', 'password']);
        });
    }
};
