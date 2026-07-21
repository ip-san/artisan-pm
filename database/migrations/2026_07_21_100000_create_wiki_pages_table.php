<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('wiki_pages')->nullOnDelete();
            $table->string('title');
            $table->boolean('is_protected')->default(false);
            $table->timestamps();

            $table->unique(['project_id', 'title']);
        });

        Schema::create('wiki_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wiki_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users');
            $table->longText('text');
            $table->string('comments')->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();

            $table->unique(['wiki_page_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_page_versions');
        Schema::dropIfExists('wiki_pages');
    }
};
