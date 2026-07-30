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
        // Matches Redmine's wikis table: a project-scoped row whose
        // start_page is a plain string, deliberately decoupled from any
        // WikiPage actually existing with that title (Wiki#find_page falls
        // back to a "new page" stub when it doesn't) — one row per project,
        // created lazily on first need rather than at project creation.
        Schema::create('wikis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('start_page')->default('Wiki');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wikis');
    }
};
