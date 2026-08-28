<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\TenantFeature;
use Closure;
use Illuminate\Validation\Rules\Unique;

/**
 * `modifyRuleUsing` closure for `->unique(ignoreRecord: true, modifyRuleUsing: ...)` on any
 * form field backed by a table whose real DB constraint is `UNIQUE(organization_id, column)`.
 *
 * A bare `->unique(ignoreRecord: true)` builds `unique:table,column` — a raw DB query that does
 * NOT respect the `BelongsToOrganization` global scope, so it's stricter than the schema: two
 * tenants sharing a value (legal per the composite unique) get rejected. First found/fixed in
 * `LocationForm.php` (2026-08-27); see `.claude/rules/filament-resources.md` for the incident.
 */
class TenantScopedUniqueRule
{
    /**
     * @return Closure(Unique): Unique
     */
    public static function forCurrentTenant(): Closure
    {
        return fn (Unique $rule): Unique => $rule->where(
            'organization_id',
            // No real organization_id is ever -1, so a null tenant makes the rule a guaranteed
            // no-op instead of throwing or silently waving through same-tenant duplicates — the
            // DB constraint stays the backstop in that case.
            TenantFeature::currentTenant()?->id ?? -1
        );
    }
}
