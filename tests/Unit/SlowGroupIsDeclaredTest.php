<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Tests\TestCase;

/**
 * Pins the SLOW test tier: exactly which test classes carry `#[Group('slow')]`.
 *
 * What the tag does: the post-agent (SubagentStop) quality-gate hook runs the fast scope
 * (`--exclude-group=slow`) after an agent finishes; CI runs the full suite on every PR to develop,
 * slow classes included. If the tag silently drifts, neither direction makes a test red:
 *   - a listed class loses its tag -> the post-agent hook quietly gets slower;
 *   - a fast class gains the tag (or a tag is typo'd) -> that class quietly stops being checked after
 *     each agent run, i.e. coverage leaves the fast scope without anyone deciding it should.
 * Both are decisions, so both must show up in a diff of THIS list.
 *
 * To tag a class: add `#[Group('slow')]` at class level AND add it to SLOW_CLASSES below. To untag:
 * do the opposite. Candidates are classes that are costly (roughly >= 10 s sequential) and only
 * relevant when one area changes.
 *
 * Also fails on the ways a group tag silently does nothing or means something else:
 *   - the PHPUnit < 10 doc-comment form (`@group slow`) - PHPUnit 12 ignores it, same failure mode as
 *     NoDeadTestAnnotationsTest guards for the test marker;
 *   - any group name other than the allowed ones (a typo'd `Group('slwo')` would just never be excluded);
 *   - a subclass of a listed class that is not itself listed: PHPUnit does NOT inherit `#[Group]` from a
 *     parent, so the subclass would run every inherited slow test in the fast scope;
 *   - a test file whose class name differs from its file name (the guard cannot see it, so it fails
 *     instead of silently skipping it).
 */
class SlowGroupIsDeclaredTest extends TestCase
{
    /** The only group names the suite uses. Add a name here only together with the gate that consumes it. */
    private const ALLOWED_GROUPS = ['slow'];

    /**
     * Every class that must carry `#[Group('slow')]`, and nothing else may: the product-form Livewire
     * characterization suites, the walkthrough of every admin resource, and the catalog-vs-form parity check.
     */
    private const SLOW_CLASSES = [
        \Tests\Feature\Filament\ServiceFormEditCharacterizationTest::class,
        \Tests\Feature\Filament\ServiceFormTileHighlightsTest::class,
        \Tests\Feature\Filament\ServiceFormContentAndSpecLayoutTest::class,
        \Tests\Feature\Filament\ServiceFormCreateCharacterizationTest::class,
        \Tests\Feature\Catalog\CatalogImportFormParityTest::class,
        \Tests\Feature\Filament\ServiceFormFieldMovesTest::class,
        \Tests\Feature\Filament\ServiceFormTabsTest::class,
        \Tests\Feature\Filament\ServiceFormRentalPriceTest::class,
        \Tests\Feature\Filament\PanelWalkthroughTest::class,
    ];

    public function test_every_listed_slow_class_carries_the_group_attribute_at_class_level(): void
    {
        $missing = [];

        foreach (self::SLOW_CLASSES as $class) {
            $this->assertTrue(class_exists($class), "Listed slow class {$class} does not exist (renamed or deleted? update SLOW_CLASSES).");

            if (! in_array('slow', $this->classLevelGroups(new ReflectionClass($class)), true)) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These classes are listed in SLOW_CLASSES but lack #[Group('slow')] at class level - the fast scope "
            ."would run them:\n".implode("\n", $missing)
        );
    }

    public function test_no_other_test_class_is_tagged_slow_and_no_group_is_declared_at_method_level(): void
    {
        $unlisted = [];
        $methodLevel = [];
        $unknownGroups = [];
        $untaggedSubclasses = [];

        foreach ($this->collectClasses() as $reflection) {
            $classGroups = $this->classLevelGroups($reflection);

            foreach (self::SLOW_CLASSES as $slow) {
                if ($reflection->isSubclassOf($slow) && ! in_array($reflection->getName(), self::SLOW_CLASSES, true)) {
                    $untaggedSubclasses[] = $reflection->getName()." extends {$slow}";
                }
            }

            if (in_array('slow', $classGroups, true) && ! in_array($reflection->getName(), self::SLOW_CLASSES, true)) {
                $unlisted[] = $reflection->getName();
            }

            foreach (array_diff($classGroups, self::ALLOWED_GROUPS) as $group) {
                $unknownGroups[] = $reflection->getName()." has group '{$group}'";
            }

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }

                foreach ($method->getAttributes(Group::class) as $attribute) {
                    $methodLevel[] = $reflection->getName().'::'.$method->getName();
                }
            }
        }

        $this->assertSame([], $unlisted, "Tagged #[Group('slow')] but not listed in SLOW_CLASSES (the fast scope silently stops running them):\n".implode("\n", $unlisted));
        $this->assertSame([], $untaggedSubclasses, "#[Group] is not inherited - a subclass of a slow class runs the parent's slow tests in the fast scope. List it in SLOW_CLASSES and tag it, or stop extending:\n".implode("\n", $untaggedSubclasses));
        $this->assertSame([], $methodLevel, "Group tags are class-level only (the slow tier is a pinned list of classes):\n".implode("\n", $methodLevel));
        $this->assertSame([], $unknownGroups, 'Unknown group name (typo? it would never be excluded). Allowed: '.implode(', ', self::ALLOWED_GROUPS)."\n".implode("\n", $unknownGroups));
    }

    public function test_no_test_file_uses_the_dead_group_doc_comment(): void
    {
        // PHPUnit 12 dropped doc-comment annotations: `@group slow` compiles, runs, and is simply
        // never excluded. Anchored to a docblock line so prose containing "@group" mid-sentence is fine.
        // Scoped to `slow` on purpose: three older files carry an inert `@group skip` on tests that skip
        // themselves via markTestSkipped(), which is harmless and not this guard's business.
        $violations = [];

        foreach ($this->collectFiles() as $file) {
            if (preg_match('/^\s*\*\s*@group\s+slow\b/m', (string) file_get_contents($file)) === 1) {
                $violations[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Dead `@group` doc-comment (PHPUnit 12 ignores it) - use #[Group('slow')] instead:\n".implode("\n", $violations)
        );
    }

    /** @return list<string> the group names declared directly on the class (not inherited) */
    private function classLevelGroups(ReflectionClass $class): array
    {
        return array_values(array_map(
            fn ($attribute) => $attribute->newInstance()->name(),
            $class->getAttributes(Group::class)
        ));
    }

    /** @return list<string> */
    private function collectFiles(): array
    {
        return collect([base_path('tests/Unit'), base_path('tests/Feature')])
            ->flatMap(fn ($dir) => File::allFiles($dir))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->map(fn ($f) => $f->getPathname())
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<ReflectionClass> every class/trait declared in the default suites (Unit + Feature) */
    private function collectClasses(): array
    {
        $classes = [];
        $mismatched = [];

        foreach ($this->collectFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1
                || preg_match_all('/^(?:(?:abstract|final|readonly)\s+)*(?:class|trait)\s+(\w+)/m', $source, $declared) === 0) {
                continue; // no namespaced class/trait (a Pest-style file, a plain script)
            }

            // A file may also declare helper classes (fakes, spies); what matters is that the one named like the
            // file exists, because that is how the class is looked up below.
            if (! in_array(basename($file, '.php'), $declared[1], true)) {
                $mismatched[] = str_replace(base_path().'/', '', $file).' declares '.implode(', ', $declared[1]);

                continue;
            }

            $fqcn = $namespace[1].'\\'.basename($file, '.php');

            if (class_exists($fqcn) || trait_exists($fqcn)) {
                $classes[] = new ReflectionClass($fqcn);
            }
        }

        $this->assertSame(
            [],
            $mismatched,
            "Test file name differs from the class it declares - this guard resolves classes by file name and would skip it silently. Rename one of them:\n".implode("\n", $mismatched)
        );

        return $classes;
    }
}
