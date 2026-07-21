<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tracker_id')->constrained();
            $table->foreignId('status_id')->constrained('issue_statuses');
            $table->foreignId('priority_id')->constrained('enumerations');
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('fixed_version_id')->nullable()->constrained('versions')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->string('subject');
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedTinyInteger('done_ratio')->default(0);
            $table->timestamp('closed_on')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status_id']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
