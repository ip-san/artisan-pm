<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('field_format');
            $table->string('customized_type');
            $table->boolean('is_required')->default(false);
            $table->boolean('multiple')->default(false);
            $table->boolean('searchable')->default(false);
            $table->string('default_value')->nullable();
            $table->unsignedInteger('min_length')->nullable();
            $table->unsignedInteger('max_length')->nullable();
            $table->string('regexp')->nullable();
            $table->json('possible_values')->nullable();
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();

            $table->index(['customized_type', 'position']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->string('customized_type');
            $table->unsignedBigInteger('customized_id');
            $table->string('value_string')->nullable();
            $table->text('value_text')->nullable();
            $table->bigInteger('value_int')->nullable();
            $table->double('value_float')->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_bool')->nullable();
            $table->timestamps();

            $table->index(['customized_type', 'customized_id']);
            $table->index(['custom_field_id', 'value_string']);
            $table->index(['custom_field_id', 'value_int']);
            $table->index(['custom_field_id', 'value_date']);
        });

        Schema::create('custom_field_tracker', function (Blueprint $table) {
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tracker_id')->constrained()->cascadeOnDelete();
            $table->primary(['custom_field_id', 'tracker_id']);
        });

        Schema::create('custom_field_project', function (Blueprint $table) {
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->primary(['custom_field_id', 'project_id']);
        });

        Schema::create('custom_field_role', function (Blueprint $table) {
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['custom_field_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_role');
        Schema::dropIfExists('custom_field_project');
        Schema::dropIfExists('custom_field_tracker');
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }
};
