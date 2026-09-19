<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's projects.homepage (max 255, shown as a link on the project
     * overview and returned by the REST API).
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('homepage')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('homepage');
        });
    }
};
