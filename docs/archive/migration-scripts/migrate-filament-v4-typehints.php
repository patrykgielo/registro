<?php

/**
 * Filament v4 Type Hints Migration Script
 *
 * Migrates Get and Set type hints from:
 * - Filament\Forms\Get → Filament\Schemas\Components\Utilities\Get
 * - Filament\Forms\Set → Filament\Schemas\Components\Utilities\Set
 *
 * Affected: 2 files (SmsTemplateResource.php, EmailTemplateResource.php)
 */
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Filament v4 Type Hints Migration (Get/Set)               ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";

$stats = [
    'processed' => 0,
    'skipped' => 0,
    'get_imports_changed' => 0,
    'set_imports_added' => 0,
    'inline_set_replaced' => 0,
];

$backupDir = 'storage/app/backups/typehints-migration-'.date('Y-m-d-His');

/**
 * Process a file
 */
function processFile(string $file, string $backupDir, array &$stats): void
{
    if (! file_exists($file)) {
        echo "⚠️  File not found: $file\n";

        return;
    }

    $content = file_get_contents($file);
    $original = $content;

    echo '📝 Processing: '.basename($file)."\n";

    // Create backup
    if (! is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    $backupFile = $backupDir.'/'.basename($file);
    file_put_contents($backupFile, $original);

    $changes = [];

    // Step 1: Replace Get import
    if (strpos($content, 'use Filament\Forms\Get;') !== false) {
        $content = str_replace(
            'use Filament\Forms\Get;',
            'use Filament\Schemas\Components\Utilities\Get;',
            $content
        );
        $changes[] = 'Changed Get import';
        $stats['get_imports_changed']++;
    }

    // Step 2: Replace Set import (if exists)
    if (strpos($content, 'use Filament\Forms\Set;') !== false) {
        $content = str_replace(
            'use Filament\Forms\Set;',
            'use Filament\Schemas\Components\Utilities\Set;',
            $content
        );
        $changes[] = 'Changed Set import';
    }

    // Step 3: Replace inline Forms\Set with Set (and add import if needed)
    if (strpos($content, 'Forms\Set $set') !== false) {
        $content = str_replace('Forms\Set $set', 'Set $set', $content);
        $changes[] = 'Replaced inline Forms\\Set usage';
        $stats['inline_set_replaced']++;

        // Check if Set import is missing
        if (strpos($content, 'use Filament\Schemas\Components\Utilities\Set;') === false) {
            // Add Set import after Get import
            $content = str_replace(
                'use Filament\Schemas\Components\Utilities\Get;',
                "use Filament\Schemas\Components\Utilities\Get;\nuse Filament\Schemas\Components\Utilities\Set;",
                $content
            );
            $changes[] = 'Added Set import';
            $stats['set_imports_added']++;
        }
    }

    // Display changes
    if (! empty($changes)) {
        foreach ($changes as $change) {
            echo "   ✓ $change\n";
        }
    }

    // Save changes
    if ($content !== $original) {
        file_put_contents($file, $content);
        $stats['processed']++;
        echo "   ✅ File updated successfully\n\n";
    } else {
        $stats['skipped']++;
        echo "   ⏭️  No changes needed\n\n";
    }
}

// Files to process
$files = [
    'app/Filament/Resources/SmsTemplateResource.php',
    'app/Filament/Resources/EmailTemplateResource.php',
];

echo 'Found '.count($files)." files to process\n\n";
echo "═══════════════════════════════════════════════════════════\n\n";

foreach ($files as $file) {
    processFile($file, $backupDir, $stats);
}

// Summary
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Migration Summary                                         ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Files processed:              {$stats['processed']}\n";
echo "Files skipped:                {$stats['skipped']}\n";
echo "Get imports changed:          {$stats['get_imports_changed']}\n";
echo "Set imports added:            {$stats['set_imports_added']}\n";
echo "Inline Set usages replaced:   {$stats['inline_set_replaced']}\n";
echo "\n";

if ($stats['processed'] > 0) {
    echo "Backups saved to: $backupDir\n";
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  Next Steps                                                ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "1. Clear Laravel caches:\n";
    echo "   docker exec paradocks-app php artisan optimize:clear\n";
    echo "\n";
    echo "2. Clear Filament caches:\n";
    echo "   docker exec paradocks-app php artisan filament:optimize-clear\n";
    echo "\n";
    echo "3. Restart containers to clear OPcache:\n";
    echo "   docker restart paradocks-app paradocks-horizon paradocks-queue paradocks-scheduler\n";
    echo "\n";
    echo "4. Test the admin panel:\n";
    echo "   https://paradocks.local:8444/admin/sms-templates/12/edit\n";
    echo "   https://paradocks.local:8444/admin/email-templates\n";
    echo "\n";
    echo "✅ Migration completed successfully!\n";
    echo "\n";
    echo "🎉 Filament v4 upgrade is now COMPLETE!\n";
} else {
    echo "✅ All files are already up to date!\n";
}

echo "\n";
