<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('old_status_id')->nullable()->constrained('issue_statuses')->cascadeOnDelete();
            $table->foreignId('new_status_id')->constrained('issue_statuses')->cascadeOnDelete();
            $table->boolean('author')->default(false);
            $table->boolean('assignee')->default(false);
            $table->timestamps();

            $table->unique(
                ['tracker_id', 'role_id', 'old_status_id', 'new_status_id', 'author', 'assignee'],
                'workflow_transitions_unique'
            );
        });

        Schema::create('workflow_field_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('status_id')->constrained('issue_statuses')->cascadeOnDelete();
            $table->string('field_name');
            $table->string('rule');
            $table->boolean('author')->default(false);
            $table->boolean('assignee')->default(false);
            $table->timestamps();

            $table->unique(
                ['tracker_id', 'role_id', 'status_id', 'field_name', 'author', 'assignee'],
                'workflow_field_rules_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_field_rules');
        Schema::dropIfExists('workflow_transitions');
    }
};
