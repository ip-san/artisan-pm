<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_from_id')->constrained('issues')->cascadeOnDelete();
            $table->foreignId('issue_to_id')->constrained('issues')->cascadeOnDelete();
            $table->string('relation_type');
            $table->timestamps();

            $table->unique(['issue_from_id', 'issue_to_id', 'relation_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_relations');
    }
};
