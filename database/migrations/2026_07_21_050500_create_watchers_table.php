<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchers', function (Blueprint $table) {
            $table->id();
            $table->morphs('watchable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['watchable_type', 'watchable_id', 'user_id'], 'watchers_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchers');
    }
};
