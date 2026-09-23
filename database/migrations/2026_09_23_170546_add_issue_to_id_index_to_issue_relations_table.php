<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The relation filters look relations up from either end; issue_from_id is
 * covered by the unique index it leads, issue_to_id had no index at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issue_relations', function (Blueprint $table) {
            $table->index(['issue_to_id', 'relation_type']);
        });
    }

    public function down(): void
    {
        Schema::table('issue_relations', function (Blueprint $table) {
            $table->dropIndex(['issue_to_id', 'relation_type']);
        });
    }
};
