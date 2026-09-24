<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's projects.default_assigned_to_id and
     * issue_categories.assigned_to_id point at a Principal (a user or a
     * group). Like issues.assigned_to_group_id, a group gets its own column,
     * never set together with the user one; deleting the group clears it.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('default_assigned_to_group_id')->nullable()->after('default_assigned_to_id')
                ->constrained('groups')->nullOnDelete();
        });

        Schema::table('issue_categories', function (Blueprint $table) {
            $table->foreignId('assigned_to_group_id')->nullable()->after('assigned_to_id')
                ->constrained('groups')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_single_default_assignee_check CHECK (default_assigned_to_id IS NULL OR default_assigned_to_group_id IS NULL)');
            DB::statement('ALTER TABLE issue_categories ADD CONSTRAINT issue_categories_single_assignee_check CHECK (assigned_to_id IS NULL OR assigned_to_group_id IS NULL)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_single_default_assignee_check');
            DB::statement('ALTER TABLE issue_categories DROP CONSTRAINT IF EXISTS issue_categories_single_assignee_check');
        }

        Schema::table('issue_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to_group_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_assigned_to_group_id');
        });
    }
};
