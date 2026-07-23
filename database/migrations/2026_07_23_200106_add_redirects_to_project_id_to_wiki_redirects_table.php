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
        Schema::table('wiki_redirects', function (Blueprint $table) {
            $table->foreignId('redirects_to_project_id')->nullable()->after('redirects_to')->constrained('projects')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wiki_redirects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('redirects_to_project_id');
        });
    }
};
