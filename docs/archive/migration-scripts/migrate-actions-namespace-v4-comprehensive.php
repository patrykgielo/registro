<?php

/**
 * Comprehensive Filament v4 Actions Namespace Migration Script
 *
 * This script migrates from old namespace patterns:
 * - Tables\Actions\* → Actions\*
 * - Forms\Actions\* → Actions\*
 * - Pages\Actions\* → Actions\*
 *
 * And adds the required import: use Filament\Actions;
 */
echo "\n=== Filament v4 Actions Namespace Migration ===\n\n";

$stats = [
    'processed' => 0,
    'skipped' => 0,
    'errors' => 0,
    'files' => [],
];

$backupDir = 'storage/app/backups/actions-migration-'.date('Y-m-d-His');

/**
 * Process a single file
 */
function processFile(string $file, string $backupDir, array &$stats): void
{
    $content = file_get_contents($file);
    $original = $content;

    // Check if file uses old Actions pattern
    $hasTablesActions = strpos($content, 'Tables\Actions\\') !== false;
    $hasFormsActions = strpos($content, 'Forms\Actions\\') !== false;
    $hasPagesActions = strpos($content, 'Pages\Actions\\') !== false;

    if (! $hasTablesActions && ! $hasFormsActions && ! $hasPagesActions) {
        $stats['skipped']++;

        return;
    }

    echo "Processing: $file\n";

    // Create backup
    if (! is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    $backupFile = $backupDir.'/'.basename($file);
    file_put_contents($backupFile, $original);

    // Step 1: Add "use Filament\Actions;" import if not present
    if (strpos($content, 'use Filament\Actions;') === false) {
        // Find the last "use Filament\" import and add after it
        $lines = explode("\n", $content);
        $lastFilamentImportLine = -1;

        foreach ($lines as $index => $line) {
            if (preg_match('/^use Filament\\\\/', trim($line))) {
                $lastFilamentImportLine = $index;
            }
        }

        if ($lastFilamentImportLine >= 0) {
            // Insert after the last Filament import
            array_splice($lines, $lastFilamentImportLine + 1, 0, 'use Filament\Actions;');
            $content = implode("\n", $lines);
            echo "  ✓ Added 'use Filament\Actions;' import\n";
        }
    }

    // Step 2: Replace Tables\Actions\ with Actions\
    if ($hasTablesActions) {
        $count = 0;
        $content = str_replace('Tables\Actions\\', 'Actions\\', $content, $count);
        if ($count > 0) {
            echo "  ✓ Replaced {$count} occurrences of Tables\Actions\\\n";
        }
    }

    // Step 3: Replace Forms\Actions\ with Actions\
    if ($hasFormsActions) {
        $count = 0;
        $content = str_replace('Forms\Actions\\', 'Actions\\', $content, $count);
        if ($count > 0) {
            echo "  ✓ Replaced {$count} occurrences of Forms\Actions\\\n";
        }
    }

    // Step 4: Replace Pages\Actions\ with Actions\
    if ($hasPagesActions) {
        $count = 0;
        $content = str_replace('Pages\Actions\\', 'Actions\\', $content, $count);
        if ($count > 0) {
            echo "  ✓ Replaced {$count} occurrences of Pages\Actions\\\n";
        }
    }

    // Save changes
    if ($content !== $original) {
        file_put_contents($file, $content);
        $stats['processed']++;
        $stats['files'][] = $file;
        echo "  ✅ File updated successfully\n\n";
    } else {
        $stats['skipped']++;
        echo "  ⏭️  No changes needed\n\n";
    }
}

// Collect all files to process
$filesToProcess = array_merge(
    glob('app/Filament/Resources/*Resource.php') ?: [],
    glob('app/Filament/Resources/*/Pages/*.php') ?: [],
    glob('app/Filament/Resources/*/RelationManagers/*.php') ?: [],
    glob('app/Filament/Pages/*.php') ?: [],
    glob('app/Filament/Widgets/*.php') ?: []
);

echo 'Found '.count($filesToProcess)." files to scan\n\n";

// Process each file
foreach ($filesToProcess as $file) {
    try {
        processFile($file, $backupDir, $stats);
    } catch (Exception $e) {
        echo "  ❌ Error processing $file: ".$e->getMessage()."\n\n";
        $stats['errors']++;
    }
}

// Summary
echo "\n=== Migration Summary ===\n";
echo "Files processed: {$stats['processed']}\n";
echo "Files skipped: {$stats['skipped']}\n";
echo "Errors: {$stats['errors']}\n";

if ($stats['processed'] > 0) {
    echo "\n=== Modified Files ===\n";
    foreach ($stats['files'] as $file) {
        echo "  - $file\n";
    }
    echo "\nBackups saved to: $backupDir\n";
}

if ($stats['errors'] > 0) {
    echo "\n⚠️  Some files had errors. Check the output above.\n";
    exit(1);
}

if ($stats['processed'] > 0) {
    echo "\n✅ Migration completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Run: php artisan optimize:clear\n";
    echo "2. Run: php artisan filament:optimize-clear\n";
    echo "3. Restart Docker containers\n";
    echo "4. Test all Resources in admin panel\n";
} else {
    echo "\n✅ All files are already up to date!\n";
}

echo "\n";
