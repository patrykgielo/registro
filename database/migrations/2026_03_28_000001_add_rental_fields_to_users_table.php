<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('customer_type', ['natural_person', 'business'])->nullable()->after('company_name');
            $table->string('pesel', 11)->nullable()->after('customer_type');
            $table->string('regon', 14)->nullable()->after('pesel');
            $table->string('krs', 20)->nullable()->after('regon');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['customer_type', 'pesel', 'regon', 'krs']);
        });
    }
};
