<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('hero_overlay_color', 7)->default('#000000')->after('featured_image');
            $table->unsignedTinyInteger('hero_overlay_opacity')->default(85)->after('hero_overlay_color');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['hero_overlay_color', 'hero_overlay_opacity']);
        });
    }
};
