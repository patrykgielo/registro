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
        Schema::create('email_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('email_send_id')->comment('FK to email_sends.id');
            $table->enum('event_type', ['sent', 'delivered', 'bounced', 'complained', 'opened', 'clicked'])
                ->comment('Type of email event');
            $table->json('event_data')->nullable()->comment('Provider-specific data');
            $table->timestamp('occurred_at')->comment('When the event occurred');
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('email_send_id')
                ->references('id')
                ->on('email_sends')
                ->onDelete('cascade');

            // Indexes
            $table->index('email_send_id');
            $table->index('event_type');
            $table->index('occurred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_events');
    }
};
