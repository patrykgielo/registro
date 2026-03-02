<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Backup file path for rollback recovery.
     */
    private const BACKUP_PATH = 'migrations/layout_backup.json';

    /**
     * Run the migrations.
     *
     * Add 'layout' field to posts and promotions tables for flexible content presentation.
     * Supports PageLayout enum values: default, full-width, minimal (excludes 'home').
     *
     * If a backup file exists from a previous rollback, restore the data.
     */
    public function up(): void
    {
        // Add columns
        Schema::table('posts', function (Blueprint $table) {
            if (! Schema::hasColumn('posts', 'layout')) {
                $table->string('layout')->default('default')->after('body');
                $table->index('layout');
            }
        });

        Schema::table('promotions', function (Blueprint $table) {
            if (! Schema::hasColumn('promotions', 'layout')) {
                $table->string('layout')->default('default')->after('body');
                $table->index('layout');
            }
        });

        // Restore from backup if exists (re-migration after rollback)
        $this->restoreFromBackup();
    }

    /**
     * Reverse the migrations.
     *
     * SAFE ROLLBACK: Creates a backup of all layout data before dropping columns.
     * Data can be restored by running the migration again.
     */
    public function down(): void
    {
        // Backup data BEFORE dropping columns
        $this->createBackup();

        Schema::table('posts', function (Blueprint $table) {
            if (Schema::hasColumn('posts', 'layout')) {
                $table->dropIndex(['layout']);
                $table->dropColumn('layout');
            }
        });

        Schema::table('promotions', function (Blueprint $table) {
            if (Schema::hasColumn('promotions', 'layout')) {
                $table->dropIndex(['layout']);
                $table->dropColumn('layout');
            }
        });
    }

    /**
     * Create backup of layout data before rollback.
     */
    private function createBackup(): void
    {
        $backup = [
            'created_at' => now()->toIso8601String(),
            'posts' => [],
            'promotions' => [],
        ];

        // Backup posts layouts (only non-default to save space)
        if (Schema::hasColumn('posts', 'layout')) {
            $backup['posts'] = DB::table('posts')
                ->where('layout', '!=', 'default')
                ->pluck('layout', 'id')
                ->toArray();
        }

        // Backup promotions layouts
        if (Schema::hasColumn('promotions', 'layout')) {
            $backup['promotions'] = DB::table('promotions')
                ->where('layout', '!=', 'default')
                ->pluck('layout', 'id')
                ->toArray();
        }

        // Only create backup if there's data to save
        if (! empty($backup['posts']) || ! empty($backup['promotions'])) {
            Storage::disk('local')->put(
                self::BACKUP_PATH,
                json_encode($backup, JSON_PRETTY_PRINT)
            );

            echo "\n📦 Layout backup created: storage/app/".self::BACKUP_PATH."\n";
            echo '   Posts with custom layout: '.count($backup['posts'])."\n";
            echo '   Promotions with custom layout: '.count($backup['promotions'])."\n";
        }
    }

    /**
     * Restore layout data from backup after re-migration.
     */
    private function restoreFromBackup(): void
    {
        if (! Storage::disk('local')->exists(self::BACKUP_PATH)) {
            return;
        }

        $backup = json_decode(Storage::disk('local')->get(self::BACKUP_PATH), true);

        if (! $backup) {
            return;
        }

        $restoredPosts = 0;
        $restoredPromotions = 0;

        // Restore posts layouts
        foreach ($backup['posts'] ?? [] as $id => $layout) {
            $updated = DB::table('posts')
                ->where('id', $id)
                ->update(['layout' => $layout]);
            if ($updated) {
                $restoredPosts++;
            }
        }

        // Restore promotions layouts
        foreach ($backup['promotions'] ?? [] as $id => $layout) {
            $updated = DB::table('promotions')
                ->where('id', $id)
                ->update(['layout' => $layout]);
            if ($updated) {
                $restoredPromotions++;
            }
        }

        if ($restoredPosts > 0 || $restoredPromotions > 0) {
            echo "\n✅ Layout data restored from backup!\n";
            echo "   Posts restored: {$restoredPosts}\n";
            echo "   Promotions restored: {$restoredPromotions}\n";

            // Remove backup after successful restore
            Storage::disk('local')->delete(self::BACKUP_PATH);
            echo "   Backup file removed.\n";
        }
    }
};
