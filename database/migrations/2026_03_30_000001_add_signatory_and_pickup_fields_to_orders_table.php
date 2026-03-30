<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('signatory_id_number', 20)->nullable()->after('company_contact_name');
            $table->string('pickup_person_name', 255)->nullable()->after('signatory_id_number');
            $table->string('pickup_person_id_number', 20)->nullable()->after('pickup_person_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['signatory_id_number', 'pickup_person_name', 'pickup_person_id_number']);
        });
    }
};
