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
     * Deliberately seeds neither key. Both used to default to a mobile
     * car-wash checklist ("Usuń wartościowe przedmioty z wnętrza auta") and
     * parking-type options — a live render path
     * (resources/views/booking-wizard/steps/vehicle-location.blade.php,
     * resources/views/booking-wizard/confirmation.blade.php), not just an
     * unused admin field, so an absent value is not cosmetic here. Both
     * consumers already treat "empty" correctly: the location-type section
     * is wrapped in `@if(count($serviceLocationTypes ?? []) > 0)`, and the
     * checklist block gates on `!empty($beforeVisitItems)` (see that
     * blade file — its own hardcoded 3rd-tier fallback, not just this
     * seeder, carried the same car-wash text and was fixed in the same
     * pass). See app/docs/features/tenant-branding.md.
     */
    private function seedBookingWizardSettings(): void
    {
        //
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
     *
     * Deliberately seeds nothing: a placeholder email/phone/address here
     * (e.g. "contact@example.com", a fabricated Warsaw street) would be a
     * false claim about a tenant that never entered one — every consumer of
     * these keys (SystemSettings Contact tab, storefront header/footer,
     * maintenance pages) already treats an absent value as "don't show it",
     * not "show a guess". See app/docs/features/tenant-branding.md.
     */
    private function seedContactSettings(): void
    {
        //
    }

    /**
     * Seed appearance settings (logo configuration).
     *
     * No logo_alt default either — SettingsManager::logoAlt() already falls
     * back to appName() when unset, and a hardcoded car-wash tagline here
     * would defeat that fallback for every tenant, same bug class as the
     * bundled logo asset.
     */
    private function seedAppearanceSettings(): void
    {
        $settings = [
            'header_logo' => [null],  // null = use default asset fallback
            'footer_logo' => [null],
        ];

        $this->seedGroup('appearance', $settings);
    }

    /**
     * Seed marketing content settings.
     *
     * hero_title/hero_subtitle/services_heading/services_subheading/
     * features_heading/features_subheading/features/cta_heading/
     * cta_subheading are deliberately NOT seeded: the homepage is CMS-driven
     * (see .claude/rules/blade-components.md — there is no home.blade.php),
     * and grep confirms none of these keys is read by any Blade file — this
     * whole sub-shape is vestigial, predating the CMS migration. Seeding
     * detailing-shop copy ("Profesjonalne Pranie Tapicerki Samochodowej")
     * into a dead admin field is lower severity than a live render (nobody
     * outside the tenant's own admin ever sees it), but it is still a real
     * defect — an admin opening Settings finds a form pre-filled with
     * another industry's copy. Removing the fields/tab entirely is a
     * separate, bigger decision (out of scope here — see
     * app/docs/features/tenant-branding.md).
     *
     * important_info_heading/important_info_points are dead too, same as
     * the rest of this group — an earlier pass here claimed
     * resources/views/booking/create.blade.php renders them; that was
     * wrong (that file is itself dead code, corrected by code review — see
     * app/docs/features/tenant-branding.md). Kept and trimmed anyway on
     * their own merits: two of the three seeded points were generic
     * booking-policy statements (deposit required, 24h cancellation
     * window), the third ("Usługi realizowane na terenie klienta" —
     * mobile at-customer-location service) was the same false-business-
     * model claim as the removed prelaunch tagline, so only that one item
     * is dropped, not the whole key.
     *
     * important_info_points is a Simple Repeater
     * (SystemSettings.php:911, `->simple(...)`) — per
     * .claude/rules/filament-settings-pages.md ("Repeater Data Format"),
     * that means a FLAT array of strings, same shape as the sibling
     * before_visit_items above. NOT `[[ 'a', 'b' ]]`.
     */
    private function seedMarketingSettings(): void
    {
        $settings = [
            'important_info_heading' => ['Ważne Informacje'],
            'important_info_points' => [
                'Rezerwacja wymaga wpłaty zaliczki',
                'Możliwość anulacji do 24h przed wizytą',
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
     *
     * No tagline/description_1/description_2/launch_date default: the copy
     * this used to seed ("we come to you, not you to us", "mobile car-wash
     * and detailing booking system") describes a specific business this
     * project no longer sells (equipment_rental only — see
     * app/docs/project/business_focus or CLAUDE.md), and the seeded
     * launch_date drifts into the past the moment nobody looks at it again.
     * Only industry-agnostic boilerplate ("Coming soon", "Got questions?")
     * stays. See app/docs/features/tenant-branding.md.
     */
    private function seedPrelaunchSettings(): void
    {
        $settings = [
            'page_title' => ['Wkrótce startujemy - Registro'],
            'heading' => ['Wkrótce Ruszamy!'],
            'date_label' => ['Data startu'],
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
