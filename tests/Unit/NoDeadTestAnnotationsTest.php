<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Regression guard: PHPUnit 12 silently dropped support for the old doc-comment
 * based test-discovery marker (attributes-only now). A method decorated with it
 * contributes ZERO coverage — no warning, no failure, just quietly never runs.
 *
 * This exact class of bug went unnoticed in this project for a long time across
 * several files, discovered only during a security audit when the routes those
 * dead tests covered turned out to have a real regression. See
 * app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md
 * ("Discovered while fixing" + Layer 3 sections) for the full history.
 *
 * Fails loudly (not a warning) if any file under tests/ still uses the marker —
 * rename the method with a test_-prefix instead and delete the doc-comment.
 */
class NoDeadTestAnnotationsTest extends TestCase
{
    public function test_no_test_file_uses_the_dead_doc_comment_test_marker(): void
    {
        // Built from parts so this file's own source never contains the banned
        // literal contiguously — otherwise this test would permanently flag itself.
        $inlineMarker = '/**'.' @test '.'*/';

        // Also catches the multi-line docblock variant (marker alone on its own
        // line inside a /** ... */ block) — anchored so it can't false-positive
        // on prose/emails containing the substring "@test" (e.g. jan@test.pl).
        $multilinePattern = '/^\s*\*\s*@test\s*$/m';

        $files = collect(File::allFiles(base_path('tests')))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->map(fn ($f) => $f->getPathname())
            ->sort()
            ->values()
            ->toArray();

        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if (str_contains($content, $inlineMarker) || preg_match($multilinePattern, $content) === 1) {
                $violations[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertEmpty(
            $violations,
            'Dead PHPUnit doc-comment test marker found (PHPUnit 12 silently ignores it — '.
            "rename the method to a test_-prefixed name and delete the doc-comment instead):\n".
            implode("\n", $violations)
        );
    }
}
