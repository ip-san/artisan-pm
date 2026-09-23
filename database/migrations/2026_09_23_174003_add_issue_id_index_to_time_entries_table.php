<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The spent time filter sums an issue's time entries for every candidate
 * row; PostgreSQL does not index the issue_id foreign key on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->index('issue_id');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex(['issue_id']);
        });
    }
};
