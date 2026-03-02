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
        Schema::table('pages', function (Blueprint $table) {
            $table->boolean('show_in_menu')->default(false)->after('featured_image');
            $table->unsignedSmallInteger('menu_order')->default(0)->after('show_in_menu');
            $table->string('menu_label')->nullable()->after('menu_order');
            $table->string('menu_location')->default('header')->after('menu_label');

            $table->index(['show_in_menu', 'menu_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['show_in_menu', 'menu_order']);
            $table->dropColumn(['show_in_menu', 'menu_order', 'menu_label', 'menu_location']);
        });
    }
};
