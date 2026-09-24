<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's issues.assigned_to_id points at a Principal (a user or a
     * group); users and groups are separate tables here, so a group
     * assignee gets its own column, never set together with
     * assigned_to_id. Deleting a group unassigns its issues, like
     * Redmine's Group#remove_references_before_destroy.
     *
     * The CHECK that keeps the user and group columns exclusive exists on
     * PostgreSQL only. MySQL 8 (error 3823) and MariaDB 10.11 (error 1901)
     * refuse a CHECK on a column whose foreign key has an ON DELETE action,
     * and the group column needs ON DELETE SET NULL; a trigger instead
     * needs privileges shared hosting rarely grants. There the rule is kept
     * by the application alone: the models' saving hooks (Issue::booted(),
     * AssigneeChoice::keepSingle()) clear one column when the other is set.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->foreignId('assigned_to_group_id')->nullable()->after('assigned_to_id')
                ->constrained('groups')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE issues ADD CONSTRAINT issues_single_assignee_check CHECK (assigned_to_id IS NULL OR assigned_to_group_id IS NULL)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE issues DROP CONSTRAINT IF EXISTS issues_single_assignee_check');
        }

        Schema::table('issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to_group_id');
        });
    }
};
