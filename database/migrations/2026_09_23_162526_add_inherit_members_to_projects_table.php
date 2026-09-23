<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's projects.inherit_members: a subproject that takes on its
     * parent's members and roles. Off by default, so existing projects keep
     * exactly the members they have.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('inherit_members')->default(false)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('inherit_members');
        });
    }
};
