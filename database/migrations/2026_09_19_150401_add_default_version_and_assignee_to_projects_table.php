<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's projects.default_version_id / default_assigned_to_id: the
     * version and assignee a new issue in the project starts with. Both
     * fall back to NULL when the referenced row goes away, matching
     * Redmine's Version/Principal before_destroy nullify callbacks.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('default_version_id')->nullable()->after('parent_id')
                ->constrained('versions')->nullOnDelete();
            $table->foreignId('default_assigned_to_id')->nullable()->after('default_version_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_assigned_to_id');
            $table->dropConstrainedForeignId('default_version_id');
        });
    }
};
