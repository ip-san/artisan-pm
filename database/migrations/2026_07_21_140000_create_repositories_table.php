<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            // One repository per project for now — Redmine allows several
            // per project (with an is_default flag); that's deferred until
            // there's a real need for a repository picker in the UI.
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type')->default('git');
            $table->string('path');
            $table->string('last_synced_revision')->nullable();
            $table->timestamps();
        });

        Schema::create('changesets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('revision');
            $table->string('committer');
            $table->timestamp('committed_on');
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'revision']);
            $table->index(['repository_id', 'committed_on']);
        });

        Schema::create('changeset_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('changeset_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('action', 1);
            $table->timestamps();
        });

        Schema::create('changeset_issue', function (Blueprint $table) {
            $table->foreignId('changeset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->primary(['changeset_id', 'issue_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changeset_issue');
        Schema::dropIfExists('changeset_files');
        Schema::dropIfExists('changesets');
        Schema::dropIfExists('repositories');
    }
};
