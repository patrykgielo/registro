<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * old_values/new_values move from native `json` to `longText`: encrypted
     * payloads (App\Casts\EncryptedJsonCast) are opaque ciphertext strings,
     * not valid JSON — MySQL's native `json` column type rejects them.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->longText('old_values')->nullable()->change();
            $table->longText('new_values')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * NOTE: only safely reversible while all stored rows still hold valid
     * JSON (i.e. before any encrypted row has been written). Once encrypted
     * ciphertext exists, MySQL will reject the JSON cast on rollback.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->json('old_values')->nullable()->change();
            $table->json('new_values')->nullable()->change();
        });
    }
};
