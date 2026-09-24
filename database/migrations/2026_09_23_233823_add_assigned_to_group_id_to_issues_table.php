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
