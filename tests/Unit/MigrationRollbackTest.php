<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MigrationRollbackTest extends TestCase
{
    public function test_all_migrations_have_non_empty_down_method(): void
    {
        $files = collect(File::allFiles(database_path('migrations')))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->map(fn ($f) => $f->getPathname())
            ->sort()
            ->values()
            ->toArray();
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if (! str_contains($content, 'function down')) {
                $violations[] = basename($file).': missing down() method';

                continue;
            }

            // Extract the down() body (stops at the first closing brace — sufficient
            // for detecting structurally empty bodies; nested closures have content before
            // their first } so they register as non-empty)
            if (preg_match('/function down\(\)[^{]*\{([^}]*)\}/s', $content, $matches)) {
                $body = trim(preg_replace('/(\/\*.*?\*\/|\/\/[^\n]*|#[^\n]*)/s', '', $matches[1]));
                if ($body === '') {
                    $violations[] = basename($file).': empty down() method body (add rollback logic or throw RuntimeException)';
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Migrations with missing/empty down() rollback:\n".implode("\n", $violations)
        );
    }
}
