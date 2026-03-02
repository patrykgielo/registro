<?php

/**
 * Comprehensive Filament v4 Layout Components Migration Script
 *
 * Fixes namespace changes for layout components:
 * - Forms\Components\Section → Schemas\Components\Section
 * - Forms\Components\Grid → Schemas\Components\Grid
 *
 * Affected: 14 files (13 Resources + 1 Custom Component)
 * Total fixes: 42 occurrences
 */
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Filament v4 Layout Components Migration Script           ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";

$stats = [
    'processed' => 0,
    'skipped' => 0,
    'section_imports_added' => 0,
    'grid_imports_added' => 0,
    'section_usages_replaced' => 0,
    'grid_usages_replaced' => 0,
    'files' => [],
];

$backupDir = 'storage/app/backups/layout-components-migration-'.date('Y-m-d-His');

/**
 * Add import statement after a specific import
 */
function addImportAfter(string $content, string $afterImport, string $newImport): string
{
    // Check if import already exists
    if (strpos($content, $newImport) !== false) {
        return $content;
    }

    $lines = explode("\n", $content);
    $insertIndex = -1;

    // Find the line with the target import
    foreach ($lines as $index => $line) {
        if (strpos($line, $afterImport) !== false) {
            $insertIndex = $index;
            break;
        }
    }

    // If found, insert after it
    if ($insertIndex >= 0) {
        array_splice($lines, $insertIndex + 1, 0, $newImport);

        return implode("\n", $lines);
    }

    return $content;
}

/**
 * Process a Resource file (add import + replace usages)
 */
function processResourceFile(string $file, string $backupDir, array &$stats, string $component = 'Section'): void
{
    if (! file_exists($file)) {
        echo "⚠️  File not found: $file\n";

        return;
    }

    $content = file_get_contents($file);
    $original = $content;

    $namespace = $component === 'Section' ? 'Section' : 'Grid';
    $searchPattern = $component === 'Section' ? 'Forms\Components\Section::' : 'Forms\Components\Grid::';

    // Check if file needs processing
    $hasUsages = strpos($content, $searchPattern) !== false;

    if (! $hasUsages) {
        $stats['skipped']++;

        return;
    }

    echo '📝 Processing: '.basename($file)."\n";

    // Create backup
    if (! is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    $backupFile = $backupDir.'/'.basename($file);
    file_put_contents($backupFile, $original);

    // Step 1: Add import after Schema import
    $importLine = "use Filament\\Schemas\\Components\\{$namespace};";
    $contentBefore = $content;
    $content = addImportAfter($content, 'use Filament\Schemas\Schema;', $importLine);

    if ($content !== $contentBefore) {
        echo "   ✓ Added import: {$importLine}\n";
        if ($component === 'Section') {
            $stats['section_imports_added']++;
        } else {
            $stats['grid_imports_added']++;
        }
    }

    // Step 2: Replace usages
    $count = substr_count($content, $searchPattern);
    if ($count > 0) {
        $content = str_replace($searchPattern, "{$namespace}::", $content);
        echo "   ✓ Replaced {$count} {$namespace} usages\n";
        if ($component === 'Section') {
            $stats['section_usages_replaced'] += $count;
        } else {
            $stats['grid_usages_replaced'] += $count;
        }
    }

    // Save changes
    if ($content !== $original) {
        file_put_contents($file, $content);
        $stats['processed']++;
        $stats['files'][] = basename($file);
        echo "   ✅ File updated successfully\n\n";
    } else {
        $stats['skipped']++;
        echo "   ⏭️  No changes needed\n\n";
    }
}

/**
 * Process DurationInput.php (just change the import)
 */
function processDurationInput(string $file, string $backupDir, array &$stats): void
{
    if (! file_exists($file)) {
        echo "⚠️  File not found: $file\n";

        return;
    }

    $content = file_get_contents($file);
    $original = $content;

    // Check if already fixed
    if (strpos($content, 'use Filament\Schemas\Components\Grid;') !== false) {
        echo "⏭️  DurationInput.php already fixed\n\n";
        $stats['skipped']++;

        return;
    }

    echo "📝 Processing: DurationInput.php\n";

    // Create backup
    if (! is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    $backupFile = $backupDir.'/'.basename($file);
    file_put_contents($backupFile, $original);

    // Replace the import
    $content = str_replace(
        'use Filament\Forms\Components\Grid;',
        'use Filament\Schemas\Components\Grid;',
        $content
    );

    if ($content !== $original) {
        file_put_contents($file, $content);
        $stats['processed']++;
        $stats['grid_imports_added']++;
        $stats['files'][] = basename($file);
        echo "   ✓ Changed Grid import to Schemas namespace\n";
        echo "   ✅ File updated successfully\n\n";
    } else {
        $stats['skipped']++;
    }
}

// List of files to process
$resourceFiles = [
    'app/Filament/Resources/UserResource.php',
    'app/Filament/Resources/EmployeeResource.php',
    'app/Filament/Resources/CustomerResource.php',
    'app/Filament/Resources/AppointmentResource.php',
    'app/Filament/Resources/EmailSuppressionResource.php',
    'app/Filament/Resources/EmailTemplateResource.php',
    'app/Filament/Resources/StaffDateExceptionResource.php',
    'app/Filament/Resources/StaffVacationPeriodResource.php',
    'app/Filament/Resources/SmsSuppressionResource.php',
    'app/Filament/Resources/StaffScheduleResource.php',
    'app/Filament/Resources/SmsTemplateResource.php',
    'app/Filament/Resources/RoleResource.php',
];

$gridFiles = [
    'app/Filament/Resources/ServiceResource.php',
];

$customComponents = [
    'app/Filament/Forms/Components/DurationInput.php',
];

echo 'Found '.(count($resourceFiles) + count($gridFiles) + count($customComponents))." files to process\n\n";

// Process Resources with Section
echo "═══════════════════════════════════════════════════════════\n";
echo " Step 1: Processing Resources with Section component\n";
echo "═══════════════════════════════════════════════════════════\n\n";

foreach ($resourceFiles as $file) {
    processResourceFile($file, $backupDir, $stats, 'Section');
}

// Process ServiceResource with Grid
echo "═══════════════════════════════════════════════════════════\n";
echo " Step 2: Processing ServiceResource with Grid component\n";
echo "═══════════════════════════════════════════════════════════\n\n";

foreach ($gridFiles as $file) {
    processResourceFile($file, $backupDir, $stats, 'Grid');
}

// Process DurationInput
echo "═══════════════════════════════════════════════════════════\n";
echo " Step 3: Processing Custom Components\n";
echo "═══════════════════════════════════════════════════════════\n\n";

foreach ($customComponents as $file) {
    processDurationInput($file, $backupDir, $stats);
}

// Summary
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Migration Summary                                         ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Files processed:              {$stats['processed']}\n";
echo "Files skipped:                {$stats['skipped']}\n";
echo "Section imports added:        {$stats['section_imports_added']}\n";
echo "Grid imports added:           {$stats['grid_imports_added']}\n";
echo "Section usages replaced:      {$stats['section_usages_replaced']}\n";
echo "Grid usages replaced:         {$stats['grid_usages_replaced']}\n";
echo "\n";

if ($stats['processed'] > 0) {
    echo "Modified files:\n";
    foreach ($stats['files'] as $file) {
        echo "  ✓ $file\n";
    }
    echo "\n";
    echo "Backups saved to: $backupDir\n";
    echo "\n";
}

if ($stats['processed'] > 0) {
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
    echo "   https://paradocks.local:8444/admin/users/create\n";
    echo "   https://paradocks.local:8444/admin/employees\n";
    echo "   https://paradocks.local:8444/admin/services\n";
    echo "\n";
    echo "✅ Migration completed successfully!\n";
} else {
    echo "✅ All files are already up to date!\n";
}

echo "\n";
