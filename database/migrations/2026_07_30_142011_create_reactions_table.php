<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matches Redmine's reactions table (db/migrate/20250423065135):
     * a single "like" toggle, polymorphic across Issue/Journal/Message/
     * News/NewsComment, one per user per reactable object — there is no
     * `type` column, since Redmine's reaction feature is a single thumbs-up,
     * not a GitHub-style multi-emoji picker.
     */
    public function up(): void
    {
        Schema::create('reactions', function (Blueprint $table) {
            $table->id();
            $table->morphs('reactable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['reactable_type', 'reactable_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
