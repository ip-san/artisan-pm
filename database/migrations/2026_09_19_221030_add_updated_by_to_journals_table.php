<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine's journals.updated_by_id: who last edited the notes. It stays
     * null for a journal nobody has edited (the row's updated_at also moves
     * for other reasons, so it cannot say that).
     */
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->foreignId('updated_by_id')->nullable()->after('private_notes')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by_id');
        });
    }
};
