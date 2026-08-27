<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Location;
use App\Models\Organization;
use App\Models\PortfolioItem;
use App\Models\Post;
use App\Models\Promotion;
use App\Models\Service;
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
     * Get selectable items for a given content type.
     *
     * Items are scoped per tenant automatically via BelongsToOrganization global scope.
     *
     * @return array<int, string>
     */
    public static function optionsForType(?string $type): array
    {
        if ($type === null || ! isset(self::CONTENT_TYPES[$type])) {
            return [];
        }

        return match ($type) {
            'services' => Service::where('is_active', true)->pluck('name', 'id')->all(),
            'posts' => Post::whereNotNull('published_at')->pluck('title', 'id')->all(),
            'promotions' => Promotion::where('active', true)->pluck('title', 'id')->all(),
            'portfolio' => PortfolioItem::whereNotNull('published_at')->pluck('title', 'id')->all(),
            // City appended so an admin picking between two active branches with a
            // similar name (e.g. two "Magazyn Główny") can tell them apart in the list.
            'locations' => Location::active()->ordered()->get()
                ->mapWithKeys(fn (Location $location) => [
                    $location->id => $location->city
                        ? "{$location->name} ({$location->city})"
                        : $location->name,
                ])->all(),
            default => [],
        };
    }

    /**
     * Resolve content items by type and IDs, preserving order.
     *
     * Uses CASE WHEN ordering instead of MySQL-only FIELD() for SQLite compatibility.
     */
    public static function resolveItems(string $type, array $ids): Collection
    {
        if (empty($ids) || ! isset(self::CONTENT_TYPES[$type])) {
            return collect();
        }

        $modelClass = self::CONTENT_TYPES[$type]['model'];

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

        return $modelClass::whereIn('id', $ids)
            ->orderByRaw($orderByRaw, $bindings)
            ->get();
    }
}
