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
        Schema::table('appointments', function (Blueprint $table) {
            $table->boolean('invoice_requested')->default(false)->after('notify_sms');
            $table->string('invoice_company_name')->nullable()->after('invoice_requested');
            $table->string('invoice_nip', 10)->nullable()->after('invoice_company_name');
            $table->string('invoice_street')->nullable()->after('invoice_nip');
            $table->string('invoice_street_number', 20)->nullable()->after('invoice_street');
            $table->string('invoice_postal_code', 6)->nullable()->after('invoice_street_number');
            $table->string('invoice_city')->nullable()->after('invoice_postal_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_requested',
                'invoice_company_name',
                'invoice_nip',
                'invoice_street',
                'invoice_street_number',
                'invoice_postal_code',
                'invoice_city',
            ]);
        });
    }
};
