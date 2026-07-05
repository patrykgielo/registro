<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Accounts with NULL password (social auth) get a random unusable hash so the
        // NOT NULL constraint can be applied. The account remains locked — no password
        // matches a randomly generated bcrypt hash.
        DB::table('users')->whereNull('password')->update([
            'password' => password_hash(Str::random(40), PASSWORD_BCRYPT),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
