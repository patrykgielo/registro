<?php

declare(strict_types=1);

namespace App\View\Composers;

use Illuminate\View\View;

class PageTypeComposer
{
    private static array $routeMap = [
        'home' => 'homepage',
        'page.*' => 'homepage',
        'service.show' => 'service',
        'services.index' => 'catalogue',
        'rental.index' => 'catalogue',
        'rental.category' => 'catalogue',
        'cart.show' => 'cart',
        'checkout.show' => 'checkout',
        'checkout.return' => 'confirmation',
        'orders.show' => 'confirmation',
        'orders.index' => 'confirmation',
        'booking.*' => 'booking',
        'booking.step' => 'booking',
        'booking.create' => 'booking',
        'post.show' => 'article',
        'promotion.show' => 'article',
        'portfolio.show' => 'article',
    ];

    public function compose(View $view): void
    {
        if ($view->offsetExists('pageType')) {
            return;
        }

        $routeName = request()->route()?->getName() ?? '';
        $pageType = $this->resolve($routeName);

        $view->with('pageType', $pageType);
    }

    private function resolve(string $routeName): string
    {
        foreach (self::$routeMap as $pattern => $type) {
            if (str_ends_with($pattern, '*')) {
                $prefix = rtrim($pattern, '*');
                if (str_starts_with($routeName, $prefix)) {
                    return $type;
                }
            } elseif ($routeName === $pattern) {
                return $type;
            }
        }

        return 'unknown';
    }
}
