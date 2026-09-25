<?php

declare(strict_types=1);

use App\Enums\DashboardArea;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's My Page layout has three areas (top/left/right); this
     * app's dashboard previously had a single ordered list. Existing
     * blocks land in 'left' — position stays a single per-user sequence
     * rather than being renumbered per area, since My Page only ever
     * orders blocks within the area they're actually rendered in.
     */
    public function up(): void
    {
        Schema::table('user_dashboard_blocks', function (Blueprint $table) {
            $table->string('area')->default(DashboardArea::Left->value)->after('block_key');
        });
    }

    public function down(): void
    {
        Schema::table('user_dashboard_blocks', function (Blueprint $table) {
            $table->dropColumn('area');
        });
    }
};
