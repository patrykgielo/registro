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
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_setup_token', 64)->nullable()->index()->after('password');
            $table->timestamp('password_setup_expires_at')->nullable()->after('password_setup_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['password_setup_token']);
            $table->dropColumn(['password_setup_token', 'password_setup_expires_at']);
        });
    }
};
