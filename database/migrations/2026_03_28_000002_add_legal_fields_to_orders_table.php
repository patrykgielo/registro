<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Customer type snapshot
            $table->enum('customer_type', ['natural_person', 'business'])
                ->notNull()
                ->default('natural_person')
                ->after('customer_phone');

            // Natural person fields
            $table->string('customer_pesel', 11)->nullable()->after('customer_type');

            // Contract address (where equipment will be used / residence address for contract)
            $table->string('customer_street', 255)->nullable()->after('customer_pesel');
            $table->string('customer_building', 20)->nullable()->after('customer_street');
            $table->string('customer_apartment', 20)->nullable()->after('customer_building');
            $table->string('customer_city', 100)->nullable()->after('customer_apartment');
            $table->string('customer_postal_code', 10)->nullable()->after('customer_city');

            // Business extra fields (besides existing invoice_nip, invoice_company_name)
            $table->string('company_regon', 14)->nullable()->after('customer_postal_code');
            $table->string('company_krs', 20)->nullable()->after('company_regon');
            $table->string('company_contact_name', 255)->nullable()->after('company_krs');

            // Security deposit (kaucja) — separate from order total, NOT subject to VAT
            $table->decimal('deposit_amount', 10, 2)->notNull()->default(0)->after('company_contact_name');
            $table->enum('deposit_status', ['not_required', 'pending', 'collected', 'returned', 'partial_return', 'forfeited'])
                ->notNull()
                ->default('not_required')
                ->after('deposit_amount');
            $table->timestamp('deposit_collected_at')->nullable()->after('deposit_status');
            $table->timestamp('deposit_returned_at')->nullable()->after('deposit_collected_at');
            $table->text('deposit_notes')->nullable()->after('deposit_returned_at');

            // Legal acceptances with timestamps + IP for evidence
            $table->timestamp('rodo_accepted_at')->nullable()->after('deposit_notes');
            $table->string('rodo_accepted_ip', 45)->nullable()->after('rodo_accepted_at');
            $table->timestamp('terms_accepted_at')->nullable()->after('rodo_accepted_ip');
            $table->timestamp('withdrawal_exclusion_accepted_at')->nullable()->after('terms_accepted_at');

            // Indexes
            $table->index('customer_type');
            $table->index('deposit_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_type']);
            $table->dropIndex(['deposit_status']);

            $table->dropColumn([
                'customer_type',
                'customer_pesel',
                'customer_street',
                'customer_building',
                'customer_apartment',
                'customer_city',
                'customer_postal_code',
                'company_regon',
                'company_krs',
                'company_contact_name',
                'deposit_amount',
                'deposit_status',
                'deposit_collected_at',
                'deposit_returned_at',
                'deposit_notes',
                'rodo_accepted_at',
                'rodo_accepted_ip',
                'terms_accepted_at',
                'withdrawal_exclusion_accepted_at',
            ]);
        });
    }
};
