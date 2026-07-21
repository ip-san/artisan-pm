<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->foreignId('user_id')->constrained();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_public')->default(false);
            $table->json('filters');
            $table->json('column_names');
            $table->json('sort_criteria')->nullable();
            $table->string('group_by')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queries');
    }
};
