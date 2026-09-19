<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's custom_fields.is_filter ("used as a filter"). Redmine
     * defaults it to false; here it defaults to true because every custom
     * field has always been filterable, so existing fields (which receive
     * the column default) keep their filters.
     */
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->boolean('is_filter')->default(true)->after('searchable');
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn('is_filter');
        });
    }
};
