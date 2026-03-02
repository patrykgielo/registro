<?php

/**
 * Automated migration script for Filament v3 → v4 Resources
 *
 * Changes:
 * 1. Add BackedEnum, UnitEnum imports
 * 2. Change Form → Schema import
 * 3. Update navigationIcon property type
 * 4. Update navigationGroup property type
 * 5. Change form(Form) → form(Schema)
 * 6. Change ->schema([]) → ->components([])
 * 7. Change ->reactive() → ->live()
 * 8. Change ->actions([]) → ->recordActions([])
 * 9. Change ->bulkActions([]) → ->toolbarActions([])
 */
$resourcesDir = __DIR__.'/app/Filament/Resources';
$resources = glob($resourcesDir.'/*Resource.php');

$stats = [
    'processed' => 0,
    'skipped' => 0,
    'errors' => 0,
];

foreach ($resources as $file) {
    echo 'Processing: '.basename($file)."...\n";

    $content = file_get_contents($file);
    $original = $content;

    // 1. Add BackedEnum, UnitEnum imports if not present
    if (! str_contains($content, 'use BackedEnum;')) {
        $content = preg_replace(
            '/(namespace [^;]+;\n\n)/s',
            "$1use BackedEnum;\nuse UnitEnum;\n",
            $content,
            1
        );
    }

    // 2. Change Form → Schema import
    $content = str_replace(
        'use Filament\Forms\Form;',
        'use Filament\Schemas\Schema;',
        $content
    );

    // 3. Update navigationIcon property type
    $content = preg_replace(
        '/protected static \?string \$navigationIcon/',
        'protected static string | BackedEnum | null $navigationIcon',
        $content
    );

    // 4. Update navigationGroup property type
    $content = preg_replace(
        '/protected static \?string \$navigationGroup/',
        'protected static string | UnitEnum | null $navigationGroup',
        $content
    );

    // 5. Change form method signature
    $content = preg_replace(
        '/public static function form\(Form \$form\): Form/',
        'public static function form(Schema $schema): Schema',
        $content
    );

    // 6. Change ->schema([]) → ->components([])
    $content = preg_replace(
        '/return \$form\s*->schema\(\[/',
        'return $schema->components([',
        $content
    );

    // 7. Change ->reactive() → ->live()
    $content = str_replace('->reactive()', '->live()', $content);

    // 8. Change ->actions([]) → ->recordActions([])
    $content = str_replace('->actions([', '->recordActions([', $content);

    // 9. Change ->bulkActions([]) → ->toolbarActions([])
    $content = str_replace('->bulkActions([', '->toolbarActions([', $content);

    if ($content !== $original) {
        file_put_contents($file, $content);
        $stats['processed']++;
        echo "  ✓ Migrated\n";
    } else {
        $stats['skipped']++;
        echo "  - No changes needed\n";
    }
}

echo "\n=== Migration Complete ===\n";
echo "Processed: {$stats['processed']}\n";
echo "Skipped: {$stats['skipped']}\n";
echo "Errors: {$stats['errors']}\n";
