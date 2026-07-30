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
        Schema::table('auth_sources', function (Blueprint $table) {
            $table->string('filter')->nullable()->after('attr_mail');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auth_sources', function (Blueprint $table) {
            $table->dropColumn('filter');
        });
    }
};
