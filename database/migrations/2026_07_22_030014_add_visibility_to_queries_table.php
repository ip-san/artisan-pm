<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->string('visibility')->default('private')->after('is_public');
        });

        DB::table('queries')->where('is_public', true)->update(['visibility' => 'public']);

        Schema::table('queries', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });

        Schema::create('query_role', function (Blueprint $table) {
            $table->foreignId('query_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['query_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_role');

        Schema::table('queries', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('visibility');
        });

        DB::table('queries')->where('visibility', 'public')->update(['is_public' => true]);

        Schema::table('queries', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
