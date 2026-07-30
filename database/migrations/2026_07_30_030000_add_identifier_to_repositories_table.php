<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->string('identifier')->nullable()->after('project_id');
            // Scoped to project_id, not global — Redmine allows the same
            // identifier (e.g. "main") to be reused across different
            // projects (repository.rb:46, validates_uniqueness_of scope:
            // project_id). Postgres treats NULL as distinct in a composite
            // unique index, so any number of identifier-less repositories
            // within one project (today, every repository) coexist fine.
            $table->unique(['project_id', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'identifier']);
            $table->dropColumn('identifier');
        });
    }
};
