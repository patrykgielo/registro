<?php

/**
 * Automated migration script for Filament v3 → v4 RelationManagers
 */
$relationManagersDir = __DIR__.'/app/Filament/Resources/EmployeeResource/RelationManagers';
$managers = glob($relationManagersDir.'/*RelationManager.php');

$stats = [
    'processed' => 0,
    'skipped' => 0,
];

foreach ($managers as $file) {
    echo 'Processing: '.basename($file)."...\n";

    $content = file_get_contents($file);
    $original = $content;

    // Change Form → Schema import
    $content = str_replace(
        'use Filament\Forms\Form;',
        'use Filament\Schemas\Schema;',
        $content
    );

    // Change form method signature
    $content = preg_replace(
        '/public function form\(Form \$form\): Form/',
        'public function form(Schema $schema): Schema',
        $content
    );

    // Change ->schema([]) → ->components([])
    $content = preg_replace(
        '/return \$form\s*->schema\(\[/',
        'return $schema->components([',
        $content
    );

    // Change ->reactive() → ->live()
    $content = str_replace('->reactive()', '->live()', $content);

    // Change ->actions([]) → ->recordActions([])
    $content = str_replace('->actions([', '->recordActions([', $content);

    // Change ->bulkActions([]) → ->toolbarActions([])
    $content = str_replace('->bulkActions([', '->toolbarActions([', $content);

    // Change ->headerActions([]) → ->toolbarActions([]) if not already done
    if (! str_contains($content, '->toolbarActions([') && str_contains($content, '->headerActions([')) {
        $content = str_replace('->headerActions([', '->toolbarActions([', $content);
    }

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
