<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CheckMigrationsRollback extends Command
{
    protected $signature = 'migrations:check-rollback';

    protected $description = 'Audit all migration files for missing or empty down() methods';

    public function handle(): int
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
            $basename = basename($file);

            if (! str_contains($content, 'function down')) {
                $violations[] = [$basename, 'missing down() method'];

                continue;
            }

            if (preg_match('/function down\(\)[^{]*\{([^}]*)\}/s', $content, $matches)) {
                $body = trim(preg_replace('/(\/\*.*?\*\/|\/\/[^\n]*|#[^\n]*)/s', '', $matches[1]));
                if ($body === '') {
                    $violations[] = [$basename, 'empty down() body — add rollback logic or throw RuntimeException'];
                }
            }
        }

        if (empty($violations)) {
            $this->info('All '.count($files).' migrations have valid down() methods.');

            return Command::SUCCESS;
        }

        $this->error(count($violations).' migration(s) with rollback issues:');
        $this->table(['File', 'Issue'], $violations);

        return Command::FAILURE;
    }
}
