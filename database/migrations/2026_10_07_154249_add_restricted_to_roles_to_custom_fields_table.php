<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's `visible = false`: the field is limited to the chosen roles, and with none chosen to
     * administrators. An empty role list used to mean "everyone" with no way to say otherwise;
     * existing fields keep that (the flag is off, and a field with roles is restricted by them).
     */
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table): void {
            $table->boolean('restricted_to_roles')->default(false)->after('editable');
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table): void {
            $table->dropColumn('restricted_to_roles');
        });
    }
};
