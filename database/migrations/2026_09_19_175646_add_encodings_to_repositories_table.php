<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's repositories.log_encoding and path_encoding: the encodings
     * commit messages and path names are stored in, when not UTF-8. Blank
     * falls back to the global commit_logs_encoding / repositories_encodings.
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->string('log_encoding', 50)->nullable();
            $table->string('path_encoding', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['log_encoding', 'path_encoding']);
        });
    }
};
