<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Location;
use App\Models\Organization;
use App\Models\PortfolioItem;
use App\Models\Post;
use App\Models\Promotion;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ContentGridResolver
{
    /**
     * Registry: content_type => [label, module, model].
     *
     * @var array<string, array{label: string, module: string, model: class-string}>
     */
    private const CONTENT_TYPES = [
        'services' => ['label' => 'Usługi', 'module' => 'services', 'model' => Service::class],
        'posts' => ['label' => 'Posty', 'module' => 'website', 'model' => Post::class],
        'promotions' => ['label' => 'Promocje', 'module' => 'website', 'model' => Promotion::class],
        'portfolio' => ['label' => 'Portfolio', 'module' => 'website', 'model' => PortfolioItem::class],
        // Location the entity has no flag of its own (every tenant has a physical
        // address) — but *displaying* it on the site is a website feature, so it's
        // gated on 'website' like posts/promotions/portfolio, not left ungated.
        'locations' => ['label' => 'Lokalizacje', 'module' => 'website', 'model' => Location::class],
    ];

    /**
     * Get available content types filtered by tenant's active modules.
     *
     * If tenant is null (Platform panel / super-admin), all types are returned.
     *
     * @return array<string, string>
     */
    public static function availableContentTypes(?Organization $tenant): array
    {
        $options = [];

        foreach (self::CONTENT_TYPES as $key => $config) {
            if ($tenant === null || $tenant->hasModule($config['module'])) {
                $options[$key] = $config['label'];
            }
        }

        return $options;
    }

    /**
     * The one definition of "may the public see this item", per type. Each arm
     * reuses the scope the item's own public route already enforces
     * (ServiceController / PostController / PromotionController /
     * PortfolioController, LocationContext) so a card in the grid never links to
     * a page that 404s, and a deactivated item can never render.
     *
     * Tenant isolation comes from each model's BelongsToOrganization global scope.
     */
    private static function visibleQuery(string $type): ?Builder
    {
        return match ($type) {
            'services' => Service::visibleOnSite(),
            'posts' => Post::published(),
            'promotions' => Promotion::activeAndValid(),
            'portfolio' => PortfolioItem::published(),
            'locations' => Location::active(),
            default => null,
        };
    }

    /**
     * Label marking an option the public site does not show right now.
     * ONE meaning for every type: deactivated, draft, scheduled for later, outside the
     * promotion's date window. Picking it is allowed (prepare a page in advance; the card
     * appears by itself once the item becomes visible) but it is never rendered before that.
     */
    public const NOT_VISIBLE_SUFFIX = ' (niewidoczny na stronie)';

    /**
     * Selectable items for a content type: every row of the tenant, visible ones first and
     * unmarked, the ones not visible yet marked with NOT_VISIBLE_SUFFIX.
     *
     * Offering the not-visible ones is deliberate, for two reasons. Editorial: preparing a
     * page with next week's promotion or a scheduled post is a normal workflow. Technical:
     * Filament validates a multi-select's stored state against its options (an `in` rule), so
     * a branch deactivated AFTER it was picked would otherwise make the whole page unsaveable
     * with an error naming no item. Rendering is untouched — resolveItems() still filters.
     *
     * Not offered: other tenants' rows (global scope) and deleted ones.
     *
     * @return array<int, string>
     */
    public static function optionsForType(?string $type): array
    {
        if ($type === null || ! isset(self::CONTENT_TYPES[$type])) {
            return [];
        }

        $model = self::CONTENT_TYPES[$type]['model'];
        $visible = self::visibleQuery($type);
        $visibleIds = (clone $visible)->pluck('id')->all();
        $notVisible = $model::query()->whereNotIn('id', $visibleIds);

        if ($type === 'locations') {
            $visible->ordered();
            $notVisible->ordered();
        }

        $options = [];
        foreach ($visible->get() as $item) {
            $options[$item->getKey()] = self::label($type, $item);
        }
        foreach ($notVisible->get() as $item) {
            $options[$item->getKey()] = self::label($type, $item).self::NOT_VISIBLE_SUFFIX;
        }

        return $options;
    }

    private static function label(string $type, Model $item): string
    {
        return match ($type) {
            'services' => $item->name,
            // City appended so an admin picking between two active branches with a
            // similar name (e.g. two "Magazyn Główny") can tell them apart in the list.
            'locations' => $item->city ? "{$item->name} ({$item->city})" : $item->name,
            default => $item->title,
        };
    }

    /**
     * Resolve the VISIBLE content items by type and IDs, preserving the order of
     * $ids. Ids that are hidden (deactivated, unpublished, outside the promotion
     * window, other tenant, deleted) are dropped here, at render — the block's
     * stored ids are never touched, so re-activating an item brings it back.
     *
     * Uses CASE WHEN ordering instead of MySQL-only FIELD() for SQLite compatibility.
     */
    public static function resolveItems(string $type, array $ids): Collection
    {
        if (empty($ids) || ! isset(self::CONTENT_TYPES[$type])) {
            return collect();
        }

        $ids = array_map('intval', $ids);

        $bindings = [];
        $clauses = [];
        foreach ($ids as $index => $id) {
            $clauses[] = 'WHEN id = ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $index;
        }
        $bindings[] = count($ids);
        $orderByRaw = 'CASE '.implode(' ', $clauses).' ELSE ? END';

        return self::visibleQuery($type)
            ->whereIn('id', $ids)
            ->orderByRaw($orderByRaw, $bindings)
            ->get();
    }
}
