<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's member_roles.inherited_from: the parent project's
     * member_roles row a subproject's role was copied from. Deleting that
     * row deletes every copy made from it, down the whole chain of
     * inheriting subprojects.
     */
    public function up(): void
    {
        Schema::table('member_roles', function (Blueprint $table) {
            $table->foreignId('inherited_from')->nullable()->after('role_id')
                ->constrained('member_roles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('member_roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inherited_from');
        });
    }
};
