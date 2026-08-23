<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\TemplateKey;
use App\Models\EmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every template key an e-mail notification names must actually resolve.
 *
 * EmailService::sendFromTemplate() THROWS when the template is missing, so a key
 * without a template is not a degraded e-mail — it is no e-mail at all, plus a
 * failed job. Nothing surfaces that to the operator: the customer's order is
 * cancelled, the row lands in `failed_jobs`, and no one looks.
 *
 * Found live 2026-08-23: RENTAL_CANCELLED existed in the enum and was fully
 * wired (Rental.php dispatches RentalCancelled -> SendRentalCancelledNotification
 * -> RentalCancelledNotification) while its template existed in NO seeder and NO
 * migration. Every rental cancellation threw, on every environment, including a
 * fresh install. UAT's `failed_jobs` also held two OrderCancelledNotification
 * failures for the same shape of reason.
 *
 * DISCOVERS the notifications rather than listing them, so a class added
 * tomorrow is covered without anyone remembering to extend this test — the
 * failure mode being guarded is precisely "someone added a notification and
 * forgot the template".
 *
 * Both languages are required. The notifications pick
 * `$notifiable->preferred_language ?? 'pl'`, so an `en` user hits the English row
 * and a missing one throws exactly the same way.
 *
 * What this does NOT cover: whether the payload fills every placeholder the
 * template declares. EmailTemplate leaves unknown `{{tokens}}` verbatim, so that
 * failure reaches the customer as literal text rather than as an exception, and
 * it can only be caught by rendering — see
 * PasswordResetEmailTest::test_no_placeholder_survives_rendering for the pattern.
 */
class EveryNotificationHasItsTemplateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, list<string>> notification class => template keys
     */
    private function emailNotificationTemplateKeys(): array
    {
        $found = [];

        foreach (glob(app_path('Notifications/*.php')) as $file) {
            $source = file_get_contents($file);

            // Only notifications that go through EmailService. A `'mail'`-channel
            // notification (e.g. DataExportCompletedNotification) builds its own
            // MailMessage and needs no row in email_templates.
            if (! str_contains($source, 'toEmailService')) {
                continue;
            }

            preg_match_all('/TemplateKey::([A-Z_]+)/', $source, $matches);

            $keys = [];
            foreach (array_unique($matches[1]) as $constant) {
                $case = constant(TemplateKey::class.'::'.$constant);
                $keys[] = $case->value;
            }

            if ($keys !== []) {
                $found[basename($file, '.php')] = $keys;
            }
        }

        return $found;
    }

    public function test_every_email_notification_resolves_its_template(): void
    {
        $notifications = $this->emailNotificationTemplateKeys();

        $this->assertNotEmpty($notifications,
            'discovered no e-mail notifications at all — the scan is broken, not the templates');

        $missing = [];

        foreach ($notifications as $class => $keys) {
            foreach ($keys as $key) {
                foreach (['pl', 'en'] as $language) {
                    if (EmailTemplate::resolveActive($key, $language) === null) {
                        $missing[] = "{$class} -> {$key} ({$language})";
                    }
                }
            }
        }

        $this->assertSame([], $missing,
            "e-mail notifications naming a template that does not exist:\n  ".
            implode("\n  ", $missing).
            "\n\nsendFromTemplate() throws on these — the customer gets nothing and the job fails.");
    }

    /**
     * A notification whose second, less obvious key is missing fails just as
     * hard as one whose only key is. OrderPaidNotification carries two
     * (ADMIN_NEW_ORDER for the operator, ORDER_PAID for the customer) and an
     * earlier audit of this codebase reported only the first, because it read one
     * match per file.
     */
    public function test_the_scan_sees_every_key_in_a_file_not_just_the_first(): void
    {
        $keys = $this->emailNotificationTemplateKeys();

        $this->assertArrayHasKey('OrderPaidNotification', $keys);
        $this->assertEqualsCanonicalizing(
            [TemplateKey::ADMIN_NEW_ORDER->value, TemplateKey::ORDER_PAID->value],
            $keys['OrderPaidNotification'],
            'the scan collapsed a multi-key notification to one key'
        );
    }
}
