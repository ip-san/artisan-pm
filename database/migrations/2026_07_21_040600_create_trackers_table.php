<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trackers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
        });

        Schema::create('project_tracker', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tracker_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'tracker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tracker');
        Schema::dropIfExists('trackers');
    }
};
