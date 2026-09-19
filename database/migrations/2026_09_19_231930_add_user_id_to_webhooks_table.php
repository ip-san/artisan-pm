<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's Webhook#user: a hook can belong to a user, and then fires only
     * for what that user may see (and only where they hold use_webhooks). A
     * hook without an owner — every existing one — fires for everything, as
     * before.
     */
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
