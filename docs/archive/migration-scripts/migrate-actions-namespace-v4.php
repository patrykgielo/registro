<?php

/**
 * Automated migration script for Filament v4 Actions namespace changes
 *
 * Changes:
 * 1. Replace use Filament\Tables\Actions\* → use Filament\Actions\*
 * 2. Replace use Filament\Forms\Actions\* → use Filament\Actions\*
 *
 * Filament v4 unified all Actions into a single namespace: Filament\Actions
 */
$stats = [
    'resources' => ['processed' => 0, 'skipped' => 0],
    'relation_managers' => ['processed' => 0, 'skipped' => 0],
    'pages' => ['processed' => 0, 'skipped' => 0],
];

echo "=== Filament v4 Actions Namespace Migration ===\n\n";

// 1. Migrate Resources
echo "Processing Resources...\n";
$resourcesDir = __DIR__.'/app/Filament/Resources';
$resources = glob($resourcesDir.'/*Resource.php');

foreach ($resources as $file) {
    $basename = basename($file);
    echo "  Processing: $basename...";

    $content = file_get_contents($file);
    $original = $content;

    // Replace Filament\Tables\Actions\ → Filament\Actions\
    $content = str_replace(
        'use Filament\\Tables\\Actions\\',
        'use Filament\\Actions\\',
        $content
    );

    // Replace Filament\Forms\Actions\ → Filament\Actions\ (if exists)
    $content = str_replace(
        'use Filament\\Forms\\Actions\\',
        'use Filament\\Actions\\',
        $content
    );

    if ($content !== $original) {
        file_put_contents($file, $content);
        $stats['resources']['processed']++;
        echo " ✓ Migrated\n";
    } else {
        $stats['resources']['skipped']++;
        echo " - No changes needed\n";
    }
}

// 2. Migrate RelationManagers
echo "\nProcessing RelationManagers...\n";
$relationManagersDir = __DIR__.'/app/Filament/Resources/EmployeeResource/RelationManagers';
$managers = glob($relationManagersDir.'/*RelationManager.php');

foreach ($managers as $file) {
    $basename = basename($file);
    echo "  Processing: $basename...";

    $content = file_get_contents($file);
    $original = $content;

    // Replace Filament\Tables\Actions\ → Filament\Actions\
    $content = str_replace(
        'use Filament\\Tables\\Actions\\',
        'use Filament\\Actions\\',
        $content
    );

    // Replace Filament\Forms\Actions\ → Filament\Actions\
    $content = str_replace(
        'use Filament\\Forms\\Actions\\',
        'use Filament\\Actions\\',
        $content
    );

    if ($content !== $original) {
        file_put_contents($file, $content);
        $stats['relation_managers']['processed']++;
        echo " ✓ Migrated\n";
    } else {
        $stats['relation_managers']['skipped']++;
        echo " - No changes needed\n";
    }
}

// 3. Migrate Pages
echo "\nProcessing Pages...\n";
$pagesDir = __DIR__.'/app/Filament/Pages';
$pages = glob($pagesDir.'/*.php');

foreach ($pages as $file) {
    $basename = basename($file);
    echo "  Processing: $basename...";

    $content = file_get_contents($file);
    $original = $content;

    // Replace Filament\Tables\Actions\ → Filament\Actions\
    $content = str_replace(
        'use Filament\\Tables\\Actions\\',
        'use Filament\\Actions\\',
        $content
    );

    // Replace Filament\Forms\Actions\ → Filament\Actions\
    $content = str_replace(
        'use Filament\\Forms\\Actions\\',
        'use Filament\\Actions\\',
        $content
    );

    // Replace Filament\Pages\Actions\ → Filament\Actions\ (if exists)
    $content = str_replace(
        'use Filament\\Pages\\Actions\\',
        'use Filament\\Actions\\',
        $content
    );

    if ($content !== $original) {
        file_put_contents($file, $content);
        $stats['pages']['processed']++;
        echo " ✓ Migrated\n";
    } else {
        $stats['pages']['skipped']++;
        echo " - No changes needed\n";
    }
}

// Print summary
echo "\n=== Migration Complete ===\n";
echo "Resources:\n";
echo "  Processed: {$stats['resources']['processed']}\n";
echo "  Skipped: {$stats['resources']['skipped']}\n";
echo "\nRelationManagers:\n";
echo "  Processed: {$stats['relation_managers']['processed']}\n";
echo "  Skipped: {$stats['relation_managers']['skipped']}\n";
echo "\nPages:\n";
echo "  Processed: {$stats['pages']['processed']}\n";
echo "  Skipped: {$stats['pages']['skipped']}\n";
