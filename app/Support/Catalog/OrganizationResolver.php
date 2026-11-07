<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Models\Organization;

/**
 * The `{organization}` argument of the catalog:* commands.
 *
 *  - `id:123`  -> by id;   `slug:abc` -> by slug (explicit, never ambiguous);
 *  - a bare number is an id *and* might be a slug ("100"): if both an organization with that id
 *    and a DIFFERENT organization with that slug exist, it is an error that names both prefixed
 *    forms — silently picking one would import into the wrong tenant;
 *  - anything else is a slug.
 */
final class OrganizationResolver
{
    /**
     * @return array{org: ?Organization, error: ?string}
     */
    public static function resolve(string $argument): array
    {
        $argument = trim($argument);

        if (str_starts_with($argument, 'id:')) {
            $id = substr($argument, 3);

            return self::found(ctype_digit($id) ? Organization::find((int) $id) : null, $argument);
        }

        if (str_starts_with($argument, 'slug:')) {
            $slug = substr($argument, 5);

            return self::found($slug === '' ? null : Organization::where('slug', $slug)->first(), $argument);
        }

        if (ctype_digit($argument)) {
            $byId = Organization::find((int) $argument);
            $bySlug = Organization::where('slug', $argument)->first();

            if ($byId !== null && $bySlug !== null && $byId->id !== $bySlug->id) {
                return ['org' => null, 'error' => "Argument '{$argument}' jest niejednoznaczny: to id organizacji '{$byId->name}' ORAZ slug organizacji '{$bySlug->name}'. "
                    ."Podaj id:{$argument} albo slug:{$argument}."];
            }

            return self::found($byId ?? $bySlug, $argument);
        }

        return self::found(Organization::where('slug', $argument)->first(), $argument);
    }

    /**
     * @return array{org: ?Organization, error: ?string}
     */
    private static function found(?Organization $org, string $argument): array
    {
        return $org === null
            ? ['org' => null, 'error' => 'Organizacja nie znaleziona: '.ConsoleText::safe($argument)]
            : ['org' => $org, 'error' => null];
    }
}
