<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_enumerations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedInteger('position')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['custom_field_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_enumerations');
    }
};
