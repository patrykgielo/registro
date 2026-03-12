<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\Organization;
use App\Rules\ValidOrganizationSlug;
use Illuminate\Support\Str;

class GenerateUniqueSlug
{
    /**
     * Generate a unique, DNS-safe slug from an organization name.
     */
    public function execute(string $name): string
    {
        $base = Str::ascii($name);
        $base = strtolower($base);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base);
        $base = trim($base, '-');
        $base = preg_replace('/-{2,}/', '-', $base);
        $base = Str::limit($base, 50, '');
        $base = rtrim($base, '-');

        if (strlen($base) < 3) {
            $base = $base.Str::random(3 - strlen($base));
        }

        $reserved = ValidOrganizationSlug::reservedSlugs();

        $slug = $base;
        $suffix = 2;

        while (
            in_array($slug, $reserved, true)
            || Organization::withoutGlobalScopes()->where('slug', $slug)->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;

            if ($suffix > 99) {
                throw new \RuntimeException('Unable to generate unique slug after 99 attempts.');
            }
        }

        return $slug;
    }
}
