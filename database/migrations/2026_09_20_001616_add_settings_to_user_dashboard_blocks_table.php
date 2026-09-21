<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A block can carry its own settings (Redmine's my_page_settings), and the
     * same saved query may sit on the page up to three times, each with its
     * own columns and sort — so a user no longer has one row per block key.
     * The one-per-key rule of the other blocks is kept by the page itself.
     *
     * The plain index is added before the unique one is dropped (and the
     * reverse in down()): MySQL will not drop the only index its user_id
     * foreign key can use.
     */
    public function up(): void
    {
        Schema::table('user_dashboard_blocks', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('position');
            $table->index(['user_id', 'block_key']);
            $table->dropUnique(['user_id', 'block_key']);
        });
    }

    public function down(): void
    {
        Schema::table('user_dashboard_blocks', function (Blueprint $table) {
            $table->unique(['user_id', 'block_key']);
            $table->dropIndex(['user_id', 'block_key']);
            $table->dropColumn('settings');
        });
    }
};
