<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropUnique(['project_id']);
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->boolean('is_default')->default(false)->after('project_id');
        });

        // Redmine's equivalent migration (20120115143100_add_is_default_to_repositories)
        // backfills every pre-existing repository as its project's default,
        // since is_default previously didn't exist and every row was
        // implicitly "the" repository for its project (the table had a
        // unique(project_id) constraint before this migration).
        DB::table('repositories')->update(['is_default' => true]);
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn('is_default');
            $table->dropForeign(['project_id']);
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique('project_id');
        });
    }
};
