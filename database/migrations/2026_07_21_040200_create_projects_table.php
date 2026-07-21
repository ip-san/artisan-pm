<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('identifier')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(true);
            $table->string('status')->default('active');
            $table->foreignId('parent_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->unsignedInteger('_lft')->default(0);
            $table->unsignedInteger('_rgt')->default(0);
            $table->timestamps();

            $table->index(['_lft', '_rgt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
