<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's roles.default_time_entry_activity_id: the activity a member
     * holding this role starts with when logging time. NULL when the
     * activity is deleted.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('default_time_entry_activity_id')->nullable()->after('assignable')
                ->constrained('enumerations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_time_entry_activity_id');
        });
    }
};
