<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's progressbar field attribute `ratio_interval`: the step of
     * the select offered for a value. NULL means "use the site default"
     * (Setting issue_done_ratio_interval).
     */
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->unsignedSmallInteger('ratio_interval')->nullable()->after('regexp');
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn('ratio_interval');
        });
    }
};
