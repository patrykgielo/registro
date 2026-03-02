<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('billing_street', 255)->nullable()->after('phone_e164');
            $table->string('billing_building_number', 20)->nullable();
            $table->string('billing_apartment_number', 20)->nullable();
            $table->string('billing_postal_code', 10)->nullable();
            $table->string('billing_city', 100)->nullable();
            $table->string('nip', 15)->nullable();
            $table->string('company_name', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'billing_street',
                'billing_building_number',
                'billing_apartment_number',
                'billing_postal_code',
                'billing_city',
                'nip',
                'company_name',
            ]);
        });
    }
};
