<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('all_roles_managed')->default(true)->after('assignable');
        });

        Schema::create('role_managed_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('managed_role_id')->constrained('roles')->cascadeOnDelete();

            $table->primary(['role_id', 'managed_role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_managed_role');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('all_roles_managed');
        });
    }
};
