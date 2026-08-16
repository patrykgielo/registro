<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Template Key Registry
 *
 * Single source of truth for all SMS and Email template identifiers.
 * Each case maps to a `key` column in sms_templates / email_templates tables.
 *
 * Usage:
 * - Notifications pass TemplateKey::XYZ->value to sendFromTemplate()
 * - Filament Resources use ::optionsForChannel() for admin selectors
 * - ReminderConfigResource uses ::reminderOptions() for reminder-specific keys
 */
enum TemplateKey: string
{
    // ── Appointment lifecycle (SMS + Email) ──────────────────────
    case APPOINTMENT_CREATED = 'appointment-created';
    case APPOINTMENT_CONFIRMED = 'appointment-confirmed';
    case APPOINTMENT_RESCHEDULED = 'appointment-rescheduled';
    case APPOINTMENT_CANCELLED = 'appointment-cancelled';

    // ── Reminders (SMS + Email) ──────────────────────────────────
    case APPOINTMENT_REMINDER_24H = 'appointment-reminder-24h';
    case APPOINTMENT_REMINDER_2H = 'appointment-reminder-2h';
    case APPOINTMENT_FOLLOWUP = 'appointment-followup';

    // ── User account (Email only) ────────────────────────────────
    case USER_REGISTERED = 'user-registered';
    case PASSWORD_RESET = 'password-reset';

    // ── Tenant onboarding (Email only) ───────────────────────────
    // TENANT_WELCOME goes to the person who just registered a business.
    // TENANT_REGISTERED_OPERATOR goes to whoever runs this Registro installation.
    case TENANT_WELCOME = 'tenant-welcome';
    case TENANT_REGISTERED_OPERATOR = 'tenant-registered-operator';

    // ── Email change flow (Email only) ───────────────────────────
    case EMAIL_CHANGE_REQUESTED = 'email-change-requested';
    case EMAIL_CHANGE_VERIFICATION = 'email-change-verification';
    case EMAIL_CHANGE_COMPLETED = 'email-change-completed';

    // ── Account deletion (Email only) ────────────────────────────
    case ACCOUNT_DELETION_REQUESTED = 'account-deletion-requested';
    case ACCOUNT_DELETION_COMPLETED = 'account-deletion-completed';

    // ── Service area waitlist (Email only) ──────────────────────
    case SERVICE_AREA_AVAILABLE = 'service-area-available';

    // ── Order lifecycle (Email only) ─────────────────────────────
    case ORDER_ACCEPTED_OFFLINE = 'order-accepted-offline';
    case ORDER_PAID = 'order-paid';
    case ORDER_CONFIRMED = 'order-confirmed';
    case ORDER_CANCELLED = 'order-cancelled';
    case ORDER_HANDED_OVER = 'order-handed-over';
    case ORDER_RETURNED = 'order-returned';
    case ADMIN_NEW_ORDER = 'admin-new-order';

    // ── Rental lifecycle (Email only) ────────────────────────────
    case RENTAL_CANCELLED = 'rental-cancelled';
    case RENTAL_EXTENSION_REQUESTED = 'rental-extension-requested';
    case RENTAL_EXTENSION_APPROVED = 'rental-extension-approved';
    case RENTAL_EXTENSION_REJECTED = 'rental-extension-rejected';

    // ── Rental return reminders (Email only) ─────────────────────
    case RENTAL_RETURN_DUE_SOON = 'rental-return-due-soon';
    case RENTAL_RETURN_OVERDUE = 'rental-return-overdue';

    // ── Admin (Email only) ───────────────────────────────────────
    case ADMIN_DAILY_DIGEST = 'admin-daily-digest';
    case ADMIN_USER_CREATED = 'admin-user-created';

    /**
     * Get human-readable label for admin UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::APPOINTMENT_CREATED => 'Potwierdzenie rezerwacji',
            self::APPOINTMENT_CONFIRMED => 'Potwierdzenie przez admina',
            self::APPOINTMENT_RESCHEDULED => 'Zmiana terminu',
            self::APPOINTMENT_CANCELLED => 'Anulowanie wizyty',
            self::APPOINTMENT_REMINDER_24H => 'Przypomnienie 24h przed',
            self::APPOINTMENT_REMINDER_2H => 'Przypomnienie 2h przed',
            self::APPOINTMENT_FOLLOWUP => 'Follow-up po wizycie',
            self::USER_REGISTERED => 'Rejestracja konta',
            self::PASSWORD_RESET => 'Reset hasla',
            self::EMAIL_CHANGE_REQUESTED => 'Zmiana email (stary adres)',
            self::EMAIL_CHANGE_VERIFICATION => 'Zmiana email (weryfikacja)',
            self::EMAIL_CHANGE_COMPLETED => 'Zmiana email (potwierdzenie)',
            self::ACCOUNT_DELETION_REQUESTED => 'Usuwanie konta (zgloszenie)',
            self::ACCOUNT_DELETION_COMPLETED => 'Usuwanie konta (potwierdzenie)',
            self::SERVICE_AREA_AVAILABLE => 'Strefa serwisowa dostępna',
            self::ORDER_ACCEPTED_OFFLINE => 'Zamówienie przyjęte (płatność przy odbiorze)',
            self::ORDER_PAID => 'Potwierdzenie zamówienia',
            self::ORDER_CONFIRMED => 'Zamówienie potwierdzone',
            self::ORDER_CANCELLED => 'Zamówienie anulowane',
            self::ORDER_HANDED_OVER => 'Sprzęt wydany klientowi',
            self::ORDER_RETURNED => 'Sprzęt zwrócony',
            self::ADMIN_NEW_ORDER => 'Nowe zamówienie (admin)',
            self::RENTAL_CANCELLED => 'Wypożyczenie anulowane',
            self::RENTAL_EXTENSION_REQUESTED => 'Wniosek o przedłużenie (admin)',
            self::RENTAL_EXTENSION_APPROVED => 'Przedłużenie zatwierdzone (klient)',
            self::RENTAL_EXTENSION_REJECTED => 'Przedłużenie odrzucone (klient)',
            self::RENTAL_RETURN_DUE_SOON => 'Przypomnienie o zwrocie (dzień przed)',
            self::RENTAL_RETURN_OVERDUE => 'Zwrot po terminie',
            self::ADMIN_DAILY_DIGEST => 'Raport dzienny admina',
            self::ADMIN_USER_CREATED => 'Konto utworzone przez admina',
        };
    }

    /**
     * Get channels that support this template.
     *
     * @return array<string>
     */
    public function channels(): array
    {
        return match ($this) {
            self::APPOINTMENT_CREATED,
            self::APPOINTMENT_CONFIRMED,
            self::APPOINTMENT_RESCHEDULED,
            self::APPOINTMENT_CANCELLED,
            self::APPOINTMENT_REMINDER_24H,
            self::APPOINTMENT_REMINDER_2H,
            self::APPOINTMENT_FOLLOWUP => ['sms', 'email'],

            default => ['email'],
        };
    }

    /**
     * Check if this template supports a given channel.
     */
    public function supportsChannel(string $channel): bool
    {
        return in_array($channel, $this->channels(), true);
    }

    /**
     * Get all options for Filament select fields.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $key) => [$key->value => $key->label()])
            ->toArray();
    }

    /**
     * Get options filtered by channel.
     *
     * @return array<string, string>
     */
    public static function optionsForChannel(string $channel): array
    {
        return collect(self::cases())
            ->filter(fn (self $key) => $key->supportsChannel($channel))
            ->mapWithKeys(fn (self $key) => [$key->value => $key->label()])
            ->toArray();
    }

    /**
     * Get reminder-eligible template options for a channel.
     * Used by ReminderConfigResource — only appointment reminders and followups.
     *
     * @return array<string, string>
     */
    public static function reminderOptions(string $channel): array
    {
        $reminderKeys = [
            self::APPOINTMENT_REMINDER_24H,
            self::APPOINTMENT_REMINDER_2H,
            self::APPOINTMENT_FOLLOWUP,
        ];

        return collect($reminderKeys)
            ->filter(fn (self $key) => $key->supportsChannel($channel))
            ->mapWithKeys(fn (self $key) => [$key->value => $key->label()])
            ->toArray();
    }
}
