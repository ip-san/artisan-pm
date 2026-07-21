<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('auth_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(389);
            $table->boolean('use_tls')->default(false);
            $table->string('base_dn');
            // Null account/account_password means direct-bind mode: the
            // submitted login builds the user's DN directly. Set means
            // search-then-bind: this service account searches for the
            // user's DN, then rebinds as it to verify the password.
            $table->string('account')->nullable();
            $table->text('account_password')->nullable();
            $table->string('attr_login')->default('uid');
            $table->string('attr_name')->default('cn');
            $table->string('attr_mail')->default('mail');
            $table->boolean('onthefly_register')->default(false);
            $table->unsignedTinyInteger('timeout')->default(5);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth_sources');
    }
};
