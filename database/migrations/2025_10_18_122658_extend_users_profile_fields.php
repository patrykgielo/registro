
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Extends user profile with personal data, contact information, and address fields.
     * Migrates existing 'name' data to 'first_name' and 'last_name'.
     *
     * New fields validation rules (documented for application layer):
     * - phone_e164: E.164 format regex: /^\+\d{1,3}\d{6,14}$/
     * - postal_code: Polish postal code format: /^\d{2}-\d{3}$/
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Personal data
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');

            // Contact information
            $table->string('phone_e164', 20)->nullable()->after('email_verified_at')
                ->comment('Phone in E.164 format (e.g., +48501234567)');

            // Address fields
            $table->string('street_name')->nullable()->after('phone_e164');
            $table->string('street_number', 20)->nullable()->after('street_name');
            $table->string('city')->nullable()->after('street_number');
            $table->string('postal_code', 10)->nullable()->after('city')
                ->comment('Postal code (e.g., 00-000)');
            $table->text('access_notes')->nullable()->after('postal_code')
                ->comment('Additional access information for address');
        });

        // Data migration: Split existing 'name' field into first_name and last_name
        $users = DB::table('users')->get();
        foreach ($users as $user) {
            $nameParts = explode(' ', $user->name, 2);
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'first_name' => $nameParts[0] ?? null,
                    'last_name' => $nameParts[1] ?? null,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'phone_e164',
                'street_name',
                'street_number',
                'city',
                'postal_code',
                'access_notes',
            ]);
        });
    }
};
