<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Http\Middleware\ResolveTenant;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The storefront pages, fetched through real routes, once under `pl` and once under `en`. A page that
 * still has hardcoded Polish passes the key-parity tests and fails here: the English run would show the
 * Polish string. The lint test (NoHardcodedPolishInViewsTest) finds the string in the source; this
 * proves the wiring (right key, right placeholder, plural form, currency) in the rendered HTML.
 */
class StorefrontLocaleHttpTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->org = Organization::factory()->equipmentRental()->create();
        $this->resolveTenantAs($this->org);
    }

    private function resolveTenantAs(Organization $org): void
    {
        $this->app->bind(ResolveTenant::class, fn () => new class($org)
        {
            public function __construct(private Organization $org) {}

            public function handle($request, $next)
            {
                $request->attributes->set('tenant', $this->org);

                return $next($request);
            }
        });
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function locales(): array
    {
        return ['pl' => ['pl'], 'en' => ['en']];
    }

    /**
     * @param  array{pl: list<string>, en: list<string>}  $expected
     */
    private function assertShows(string $html, string $locale, array $expected): void
    {
        $other = $locale === 'pl' ? 'en' : 'pl';

        foreach ($expected[$locale] as $text) {
            $this->assertStringContainsString($text, $html, "[{$locale}] expected text missing: {$text}");
        }
        // The other language's rendering of the same strings must be absent — this is what catches a
        // hardcoded string that happens to match the pl run.
        foreach ($expected[$other] as $text) {
            $this->assertStringNotContainsString($text, $html, "[{$locale}] text of the other language leaked: {$text}");
        }
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function userWithRentalItemInCart(int $quantity = 1): array
    {
        $user = User::factory()->create();
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'name' => 'Wiertarka udarowa',
            'quantity_total' => 5,
            'price_per_day' => 120,
            'deposit_amount' => 200,
        ]);
        $cart = Cart::factory()->active()->create(['user_id' => $user->id, 'organization_id' => $this->org->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'quantity' => $quantity,
            'rental_days' => 3,
            'unit_price' => 120,
            'total_price' => 360 * $quantity,
        ]);

        return [$user, $service];
    }

    #[DataProvider('locales')]
    public function test_login_page_follows_the_app_locale(string $locale): void
    {
        app()->setLocale($locale);

        $html = $this->get('http://registro.local/login')->assertOk()->getContent();

        $this->assertShows($html, $locale, [
            'pl' => ['Witaj ponownie', 'Zapomniałeś hasła?', 'Zapamiętaj mnie', 'Wprowadź hasło'],
            'en' => ['Welcome back', 'Forgot your password?', 'Remember me', 'Enter your password'],
        ]);
    }

    #[DataProvider('locales')]
    public function test_cart_page_follows_the_app_locale_including_plural_and_currency(string $locale): void
    {
        app()->setLocale($locale);
        [$user] = $this->userWithRentalItemInCart();

        $html = $this->actingAs($user)->get(route('cart.show'))->assertOk()->getContent();

        $this->assertShows($html, $locale, [
            'pl' => ['Twój koszyk', 'Podsumowanie', 'Do kasy', 'Kontynuuj zakupy', '1 pozycja', "3\u{00A0}dni", 'zł'],
            'en' => ['Your cart', 'Summary', 'Checkout', 'Continue shopping', '1 item', "3\u{00A0}days", 'PLN'],
        ]);
    }

    public function test_the_empty_cart_follows_the_app_locale(): void
    {
        $user = User::factory()->create();

        app()->setLocale('en');
        $this->actingAs($user)->get(route('cart.show'))->assertOk()
            ->assertSee('Your cart is empty')
            ->assertDontSee('Koszyk jest pusty');

        app()->setLocale('pl');
        $this->actingAs($user)->get(route('cart.show'))->assertOk()
            ->assertSee('Koszyk jest pusty')
            ->assertDontSee('Your cart is empty');
    }

    #[DataProvider('locales')]
    public function test_product_page_follows_the_app_locale(string $locale): void
    {
        app()->setLocale($locale);
        [$user, $service] = $this->userWithRentalItemInCart();

        $html = $this->actingAs($user)->get(route('service.show', $service))->assertOk()->getContent();

        $this->assertShows($html, $locale, [
            'pl' => ['Cennik', 'za dzień', 'Kaucja zwrotna', 'Dodaj do koszyka', 'Wtorek', 'Ograniczone', '120 zł'],
            'en' => ['Pricing', 'per day', 'Refundable deposit', 'Add to cart', 'Tuesday', 'Limited', '120 PLN'],
        ]);
    }

    #[DataProvider('locales')]
    public function test_rental_listing_tile_price_follows_the_app_locale(string $locale): void
    {
        app()->setLocale($locale);
        [$user] = $this->userWithRentalItemInCart();

        $html = $this->actingAs($user)->get(route('rental.index'))->assertOk()->getContent();

        $this->assertShows($html, $locale, [
            'pl' => ['Wypożyczalnia', 'Brak dostępnych kategorii', 'Najnowsze w ofercie', '120,00 zł/dzień'],
            'en' => ['Rental shop', 'No categories available', 'Latest in our offer', '120,00 PLN/day'],
        ]);
    }

    #[DataProvider('locales')]
    public function test_checkout_page_follows_the_app_locale(string $locale): void
    {
        app()->setLocale($locale);
        [$user] = $this->userWithRentalItemInCart();

        $html = $this->actingAs($user)->get(route('checkout.show'))->assertOk()->getContent();

        $this->assertShows($html, $locale, [
            'pl' => ['Finalizacja zamówienia', 'Typ klienta', 'Osoba fizyczna', 'Zgody i oświadczenia', 'Razem za wynajem', 'Wróć do koszyka'],
            'en' => ['Checkout', 'Customer type', 'Individual', 'Consents and declarations', 'Rental total', 'Back to cart'],
        ]);
    }

    #[DataProvider('locales')]
    public function test_orders_list_follows_the_app_locale(string $locale): void
    {
        app()->setLocale($locale);

        $html = $this->actingAs(User::factory()->create())->get(route('orders.index'))->assertOk()->getContent();

        $this->assertShows($html, $locale, [
            'pl' => ['Moje zamówienia', 'Brak zamówień', 'Nie masz jeszcze żadnych zamówień.'],
            'en' => ['My orders', 'No orders', 'You have no orders yet.'],
        ]);
    }

    #[DataProvider('locales')]
    public function test_flash_message_of_the_rental_gate_middleware_follows_the_app_locale(string $locale): void
    {
        app()->setLocale($locale);
        $this->resolveTenantAs(Organization::factory()->autoDetailing()->create());

        $this->actingAs(User::factory()->create())->get(route('cart.show'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('info', [
                'pl' => 'Wypożyczalnia jest tymczasowo niedostępna. Skontaktuj się z nami telefonicznie.',
                'en' => 'The rental shop is temporarily unavailable. Please contact us by phone.',
            ][$locale]);
    }

    #[DataProvider('locales')]
    public function test_flash_message_after_adding_to_the_cart_follows_the_app_locale(string $locale): void
    {
        app()->setLocale($locale);
        $user = User::factory()->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $this->org->id, 'quantity_total' => 5, 'price_per_day' => 100]);

        $this->actingAs($user)->post(route('cart.add'), [
            'service_id' => $service->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'quantity' => 1,
        ])->assertSessionHas('success', ['pl' => 'Dodano do koszyka.', 'en' => 'Added to cart.'][$locale]);
    }

    /**
     * Polish has three plural forms (1 / 2-4 / 5+, with 12-14 and 22-24 following the last digit).
     * Laravel's MessageSelector implements them for `pl` only when the message is written as bare
     * `a|b|c`; `{1}…|[2,4]…` ranges would get 22 wrong.
     */
    public function test_polish_plural_forms_follow_the_last_digit_rule(): void
    {
        app()->setLocale('pl');

        $this->assertSame(
            ['1 pozycja', '2 pozycje', '5 pozycji', '12 pozycji', '22 pozycje', '25 pozycji'],
            array_map(fn (int $n) => trans_choice('common.positions', $n), [1, 2, 5, 12, 22, 25]),
        );

        app()->setLocale('en');
        $this->assertSame(['1 item', '2 items', '0 items'], array_map(fn (int $n) => trans_choice('common.positions', $n), [1, 2, 0]));
    }
}
