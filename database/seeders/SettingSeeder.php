<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Setting Seeder
 *
 * Seeds default application settings for all groups:
 * - booking: Business hours, slot intervals, cancellation policies
 * - map: Google Maps configuration
 * - contact: Business contact information
 * - marketing: Homepage content and messaging
 * - email: SMTP configuration and notification settings
 * - sms: SMSAPI configuration and notification settings
 */
class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedGeneralSettings();
        $this->seedAuthSettings();
        $this->seedBookingSettings();
        $this->seedBookingWizardSettings();
        $this->seedMapSettings();
        $this->seedContactSettings();
        $this->seedAppearanceSettings();
        $this->seedMarketingSettings();
        $this->seedEmailSettings();
        $this->seedSmsSettings();
        $this->seedPrelaunchSettings();
        $this->seedCheckoutSettings();
        $this->seedAccountSettings();
    }

    /**
     * Seed general application settings.
     */
    private function seedGeneralSettings(): void
    {
        $settings = [
            'app_name' => ['Registro'],
        ];

        $this->seedGroup('general', $settings);
    }

    /**
     * Seed auth settings (registration toggle).
     */
    private function seedAuthSettings(): void
    {
        $settings = [
            'registration_enabled' => [true],
        ];

        $this->seedGroup('auth', $settings);
    }

    /**
     * Seed booking configuration settings.
     */
    private function seedBookingSettings(): void
    {
        $settings = [
            'booking_enabled' => [true],
            'business_hours_start' => ['09:00'],
            'business_hours_end' => ['18:00'],
            'slot_interval_minutes' => [30],
            'advance_booking_hours' => [24],
            'cancellation_hours' => [24],
            'max_service_duration_minutes' => [480],
        ];

        $this->seedGroup('booking', $settings);
    }

    /**
     * Seed booking wizard configuration settings.
     *
     * Note: before_visit_items is a flat array for Filament simple Repeater.
     * Do NOT nest in extra array - that causes [object Object] display bug.
     */
    private function seedBookingWizardSettings(): void
    {
        $settings = [
            // Simple Repeater: flat array of strings (NOT nested!)
            'before_visit_items' => [
                'Upewnij się, że samochód jest dostępny pod wskazanym adresem',
                'Usuń wartościowe przedmioty z wnętrza auta',
                'Dostęp do wody i prądu ułatwi pracę (jeśli to możliwe)',
                'Otrzymasz przypomnienie SMS 2h przed wizytą',
            ],
            // Complex Repeater: array of objects with named fields
            'service_location_types' => [
                ['name' => 'Parking naziemny', 'icon' => 'sun', 'description' => 'Parking na zewnątrz, bez zadaszenia'],
                ['name' => 'Parking podziemny', 'icon' => 'building-office', 'description' => 'Wymagany kod dostępu do garażu'],
                ['name' => 'Podwórko/Posesja', 'icon' => 'home', 'description' => 'Prywatna posesja z dostępem'],
            ],
        ];

        $this->seedGroup('booking_wizard', $settings);
    }

    /**
     * Seed Google Maps configuration settings.
     */
    private function seedMapSettings(): void
    {
        $settings = [
            'default_latitude' => [52.2297],
            'default_longitude' => [21.0122],
            'default_zoom' => [13],
            'country_code' => ['pl'],
            'map_id' => [null],
            'debug_panel_enabled' => [false],
        ];

        $this->seedGroup('map', $settings);
    }

    /**
     * Seed business contact information settings.
     */
    private function seedContactSettings(): void
    {
        $settings = [
            'email' => ['contact@example.com'],
            'phone' => ['+48123456789'],
            'address_line' => ['ul. Marszałkowska 1'],
            'city' => ['Warszawa'],
            'postal_code' => ['00-001'],
        ];

        $this->seedGroup('contact', $settings);
    }

    /**
     * Seed appearance settings (logo configuration).
     */
    private function seedAppearanceSettings(): void
    {
        $settings = [
            'header_logo' => [null],  // null = use default asset fallback
            'footer_logo' => [null],
            'logo_alt' => ['Registro - Mobilne Myjnie Parowe'],
        ];

        $this->seedGroup('appearance', $settings);
    }

    /**
     * Seed marketing content settings.
     */
    private function seedMarketingSettings(): void
    {
        $settings = [
            'hero_title' => ['Profesjonalne Pranie Tapicerki Samochodowej'],
            'hero_subtitle' => ['Przywróć swojemu samochodowi pierwotny wygląd'],
            'services_heading' => ['Nasze Usługi'],
            'services_subheading' => ['Kompleksowa oferta detailingu'],
            'features_heading' => ['Dlaczego My?'],
            'features_subheading' => ['Gwarantujemy najwyższą jakość'],
            'features' => [
                [
                    ['title' => 'Profesjonalny Sprzęt', 'description' => 'Używamy najnowocześniejszego sprzętu do prania tapicerki'],
                    ['title' => 'Doświadczony Zespół', 'description' => 'Nasz zespół ma wieloletnie doświadczenie'],
                    ['title' => 'Gwarancja Jakości', 'description' => 'Gwarantujemy 100% satysfakcji'],
                ],
            ],
            'cta_heading' => ['Umów się już dziś'],
            'cta_subheading' => ['Skontaktuj się z nami i poznaj naszą ofertę'],
            'important_info_heading' => ['Ważne Informacje'],
            'important_info_points' => [
                [
                    'Rezerwacja wymaga wpłaty zaliczki',
                    'Możliwość anulacji do 24h przed wizytą',
                    'Usługi realizowane na terenie klienta',
                ],
            ],
        ];

        $this->seedGroup('marketing', $settings);
    }

    /**
     * Seed email system configuration settings.
     */
    private function seedEmailSettings(): void
    {
        $settings = [
            'smtp_host' => ['smtp.gmail.com'],
            'smtp_port' => [587],
            'smtp_encryption' => ['tls'],
            'smtp_username' => [null],
            'smtp_password' => [null],
            'from_name' => ['Registro'],
            'from_address' => ['noreply@registro.local'],
            'retry_attempts' => [3],
            'backoff_seconds' => [60],
            'reminder_24h_enabled' => [true],
            'reminder_2h_enabled' => [true],
            'followup_enabled' => [true],
            'admin_digest_enabled' => [true],
        ];

        $this->seedGroup('email', $settings);
    }

    /**
     * Seed SMS system configuration settings.
     */
    private function seedSmsSettings(): void
    {
        $settings = [
            'enabled' => [true],
            // api_token removed - now configured in .env as SMSAPI_TOKEN
            'service' => ['pl'],
            'sender_name' => ['Registro'],
            'test_mode' => [false],
            'send_booking_confirmation' => [true],
            'send_admin_confirmation' => [true],
            'send_reminder_24h' => [true],
            'send_reminder_2h' => [true],
            'send_follow_up' => [true],
            // Spending limits (also configurable via .env)
            'daily_limit' => [config('services.sms.daily_limit', 500)],
            'monthly_limit' => [config('services.sms.monthly_limit', 10000)],
            'alert_threshold' => [config('services.sms.alert_threshold', 80)],
            'alert_email' => [config('services.sms.alert_email', 'admin@example.com')],
        ];

        $this->seedGroup('sms', $settings);
    }

    /**
     * Seed pre-launch page default content settings.
     */
    private function seedPrelaunchSettings(): void
    {
        $settings = [
            'page_title' => ['Wkrótce startujemy - Registro'],
            'heading' => ['Wkrótce Ruszamy!'],
            'tagline' => ['Registro polega na tym, że to my przyjeżdżamy do Ciebie, a nie Ty do Nas!'],
            'date_label' => ['Data startu'],
            'launch_date' => ['2026-01-25'],
            'description_1' => ['Wprowadzamy autorski system rezerwacji mobilnych usług mycia pojazdów oraz detailingu.'],
            'description_2' => ['Świadczymy usługi we wskazanej przez Ciebie lokalizacji.'],
            'contact_heading' => ['Masz pytania?'],
            'copyright_text' => ['Registro. Wszelkie prawa zastrzeżone.'],
            'html_lang' => ['pl'],
            'background_image' => [null],
        ];

        $this->seedGroup('prelaunch', $settings);
    }

    /**
     * Seed checkout settings (consent texts, document URLs).
     */
    private function seedCheckoutSettings(): void
    {
        $settings = [
            'terms_url' => [''],
            'privacy_policy_url' => [''],
            'terms_label' => ['Akceptuję Regulamin Wypożyczalni i zapoznałem/am się z warunkami najmu sprzętu.'],
            'rodo_label' => ['Wyrażam zgodę na przetwarzanie moich danych osobowych przez {org_name} w celu realizacji umowy najmu zgodnie z art. 6 ust. 1 lit. b) RODO. Dane będą przechowywane przez 5 lat od zakończenia umowy.'],
            'withdrawal_label' => ['Przyjmuję do wiadomości, że w związku z określeniem terminów wynajmu (art. 38 ust. 1 pkt 12 Ustawy o Prawach Konsumenta) nie przysługuje mi prawo odstąpienia od umowy na odległość.'],
            'deposit_policy_note' => ['Kaucja pobierana gotówką / kartą przy odbiorze sprzętu. Zwracana po oddaniu sprzętu w stanie nienaruszonym.'],
        ];

        $this->seedGroup('checkout', $settings);
    }

    /**
     * Seed account-level settings (closure request destination).
     */
    private function seedAccountSettings(): void
    {
        $settings = [
            'closure_request_email' => ['kontakt@registro.app'],
        ];

        $this->seedGroup('account', $settings);
    }

    /**
     * Helper method to seed a group of settings.
     *
     * @param  string  $group  Group name
     * @param  array<string, array>  $settings  Key-value pairs (values already wrapped in arrays)
     */
    private function seedGroup(string $group, array $settings): void
    {
        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(
                ['group' => $group, 'key' => $key],
                ['value' => $value]
            );
        }

        $this->command->info("✓ Seeded {$group} settings");
    }
}
