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
        Schema::create('sms_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sms_send_id')->comment('FK to sms_sends.id');
            $table->enum('event_type', ['sent', 'delivered', 'failed', 'invalid_number', 'expired'])
                ->comment('Type of SMS event from SMSAPI webhook');
            $table->json('event_data')->nullable()->comment('SMSAPI-specific data from webhook');
            $table->timestamp('occurred_at')->comment('When the event occurred');
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('sms_send_id')
                ->references('id')
                ->on('sms_sends')
                ->onDelete('cascade');

            // Indexes
            $table->index('sms_send_id');
            $table->index('event_type');
            $table->index('occurred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_events');
    }
};
