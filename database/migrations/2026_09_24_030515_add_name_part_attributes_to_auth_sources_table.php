<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's AuthSourceLdap#attr_firstname / attr_lastname: the directory
     * attributes a provisioned account's first and last names come from.
     * Optional here, beside the existing single-name attribute (attr_name).
     */
    public function up(): void
    {
        Schema::table('auth_sources', function (Blueprint $table) {
            $table->string('attr_firstname')->nullable()->after('attr_name');
            $table->string('attr_lastname')->nullable()->after('attr_firstname');
        });
    }

    public function down(): void
    {
        Schema::table('auth_sources', function (Blueprint $table) {
            $table->dropColumn(['attr_firstname', 'attr_lastname']);
        });
    }
};
