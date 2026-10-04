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
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Real routes, real FormRequest / controller validation — no Validator::make. The same invalid
 * submission is replayed with the app locale set to pl and to en; the customer must read the
 * language of the locale, never raw "validation.x" keys or snake_case field names.
 */
class ValidationLocaleHttpTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->org = Organization::factory()->equipmentRental()->create();

        $org = $this->org;
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
     * @return list<string>
     */
    private function allErrors(): array
    {
        return collect(session('errors')->getBag('default')->toArray())->flatten()->all();
    }

    private function assertCleanMessages(): void
    {
        foreach ($this->allErrors() as $message) {
            $this->assertStringNotContainsString('validation.', $message, "Raw translation key leaked: {$message}");
            $this->assertDoesNotMatchRegularExpression('/\b[a-z]+(_[a-z0-9]+)+\b/', $message, "Raw field name leaked into: {$message}");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submitCheckout(array $payload): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $this->org->id, 'quantity_total' => 5]);
        $cart = Cart::factory()->active()->create(['user_id' => $user->id, 'organization_id' => $this->org->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'quantity' => 1,
            'rental_days' => 3,
            'unit_price' => 100,
            'total_price' => 300,
        ]);

        $this->actingAs($user)->post(route('checkout.submit'), $payload)->assertSessionHasErrors();
    }

    #[DataProvider('locales')]
    public function test_customer_registration_errors_follow_the_app_locale(string $locale): void
    {
        app()->setLocale($locale);

        $this->post('http://registro.local/customer/register', [
            'first_name' => '',
            'last_name' => 'Kowalski',
            'email' => 'not-an-email',
            'password' => 'password123',
            'password_confirmation' => 'different123',
        ])->assertSessionHasErrors(['first_name', 'email', 'password']);

        $errors = session('errors');
        $expected = [
            'pl' => [
                'first_name' => 'Pole imię jest wymagane.',
                'email' => 'Pole adres email musi zawierać prawidłowy adres email.',
                'password' => 'Potwierdzenie w polu hasło nie zgadza się.',
            ],
            'en' => [
                'first_name' => 'The first name field is required.',
                'email' => 'The email address field must be a valid email address.',
                'password' => 'The password field confirmation does not match.',
            ],
        ][$locale];

        foreach ($expected as $field => $message) {
            $this->assertSame($message, $errors->first($field));
        }
        $this->assertCleanMessages();
    }

    #[DataProvider('locales')]
    public function test_checkout_errors_follow_the_app_locale(string $locale): void
    {
        app()->setLocale($locale);

        $this->submitCheckout([
            'customer_type' => 'natural_person',
            'settlement_method' => 'online',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
            // customer_email deliberately missing; phone too long; PESEL too short
            'customer_phone' => str_repeat('5', 21),
            'customer_pesel' => '123',
            'customer_street' => 'Marszałkowska',
            'customer_building' => '1',
            'customer_city' => 'Warszawa',
            'customer_postal_code' => '00-001',
            // consents left out
        ]);

        $errors = session('errors');
        $expected = [
            'pl' => [
                'customer_email' => 'Adres email jest wymagany.',
                'customer_phone' => 'Liczba znaków w polu numer telefonu nie może przekraczać 20.',
                'customer_pesel' => 'PESEL musi składać się z 11 cyfr.',
                'terms_accepted' => 'Akceptacja regulaminu jest wymagana.',
                'rodo_accepted' => 'Akceptacja polityki prywatności (RODO) jest wymagana.',
                'withdrawal_exclusion_accepted' => 'Potwierdzenie wyłączenia prawa odstąpienia jest wymagane.',
            ],
            'en' => [
                'customer_email' => 'Email address is required.',
                'customer_phone' => 'The phone number field must not be greater than 20 characters.',
                'customer_pesel' => 'PESEL must consist of 11 digits.',
                'terms_accepted' => 'You must accept the terms and conditions.',
                'rodo_accepted' => 'You must accept the privacy policy (GDPR).',
                'withdrawal_exclusion_accepted' => 'Please confirm that the right of withdrawal does not apply.',
            ],
        ][$locale];

        foreach ($expected as $field => $message) {
            $this->assertSame($message, $errors->first($field), "checkout field {$field}");
        }
        $this->assertCleanMessages();
    }

    #[DataProvider('locales')]
    public function test_checkout_business_tax_id_errors_follow_the_app_locale(string $locale): void
    {
        app()->setLocale($locale);

        $this->submitCheckout([
            'customer_type' => 'business',
            'settlement_method' => 'online',
            'customer_email' => 'firma@example.com',
            'customer_phone' => '500100200',
            'invoice_company_name' => 'ACME sp. z o.o.',
            'invoice_nip' => '1234567890',
            'company_regon' => '123456789',
            'terms_accepted' => true,
            'rodo_accepted' => true,
            'withdrawal_exclusion_accepted' => true,
        ]);

        $errors = session('errors');
        $expected = [
            'pl' => ['invoice_nip' => 'Nieprawidłowy numer NIP (błąd sumy kontrolnej).', 'company_regon' => 'Nieprawidłowy numer REGON (błąd sumy kontrolnej).'],
            'en' => ['invoice_nip' => 'Invalid NIP number (checksum error).', 'company_regon' => 'Invalid REGON number (checksum error).'],
        ][$locale];

        foreach ($expected as $field => $message) {
            $this->assertSame($message, $errors->first($field), "checkout field {$field}");
        }
        $this->assertCleanMessages();
    }

    #[DataProvider('locales')]
    public function test_failed_login_message_follows_the_app_locale(string $locale): void
    {
        app()->setLocale($locale);
        User::factory()->create(['email' => 'jan@example.com']);

        $this->post('http://registro.local/login', ['email' => 'jan@example.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertSame(
            ['pl' => 'Podane dane logowania są nieprawidłowe.', 'en' => 'These credentials do not match our records.'][$locale],
            session('errors')->first('email'),
        );
    }

    /**
     * validation.custom is keyed by FIELD NAME and so applies to every form with that field: the
     * minimum quantity and the date it is compared with must come from the rule, not the text.
     */
    #[DataProvider('locales')]
    public function test_cart_custom_messages_take_their_values_from_the_rule(string $locale): void
    {
        app()->setLocale($locale);
        $user = User::factory()->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $this->org->id, 'quantity_total' => 5]);

        $this->actingAs($user)->post(route('cart.add'), [
            'service_id' => $service->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'quantity' => 0,
        ])->assertSessionHasErrors(['end_date', 'quantity']);

        $errors = session('errors');
        $expected = [
            'pl' => ['quantity' => 'Ilość musi wynosić co najmniej 1.', 'end_date' => 'Data zakończenia musi być równa lub późniejsza niż data rozpoczęcia.'],
            'en' => ['quantity' => 'The quantity must be at least 1.', 'end_date' => 'The end date must be the same as or later than start date.'],
        ][$locale];

        foreach ($expected as $field => $message) {
            $this->assertSame($message, $errors->first($field), "cart field {$field}");
        }

        $other = Validator::make(['quantity' => 1], ['quantity' => 'min:2']);
        $this->assertTrue($other->fails());
        $this->assertStringContainsString('2', $other->errors()->first('quantity'));
        $this->assertStringNotContainsString(' 1.', $other->errors()->first('quantity'));
    }
}
