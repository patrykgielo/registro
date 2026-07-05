<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_lifecycle_log', function (Blueprint $table) {
            $table->id();

            // No FK constraint intentionally — log rows must survive org hard-delete/purge.
            // organization_name is snapshotted so the log is readable after the org row is gone.
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('organization_name');

            $table->string('event');

            // actor_id has no cascade — actor user deletion must not remove audit evidence.
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_label')->nullable();

            $table->json('context')->nullable();

            // Append-only: no updated_at column.
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_lifecycle_log');
    }
};
