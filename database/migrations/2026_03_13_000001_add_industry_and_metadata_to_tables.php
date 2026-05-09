<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('industry')->nullable()->after('booking_type');
            $table->index('industry');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('features');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['industry']);
            $table->dropColumn('industry');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
