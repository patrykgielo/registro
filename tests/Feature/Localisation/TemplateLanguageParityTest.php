<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EmailService/SmsService throw "template not found for language 'xx'" instead of falling back
 * to another language (EmailTemplate::resolveActive() matches `language` exactly), so a key that
 * exists in only one language is a hard failure for any customer whose preferred_language is the
 * other one. Pins the seeded/migrated global templates (organization_id NULL).
 */
class TemplateLanguageParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  class-string<EmailTemplate|SmsTemplate>  $model
     * @return list<string>
     */
    private function keysMissingALanguage(string $model): array
    {
        $byKey = $model::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->get(['key', 'language'])
            ->groupBy('key')
            ->map(fn ($rows) => $rows->pluck('language')->unique()->sort()->values()->all());

        // A squashed schema or fresh install without the seeded rows would otherwise pass vacuously.
        $this->assertNotEmpty($byKey, "No global {$model} rows exist — nothing to compare.");

        return $byKey
            ->reject(fn (array $languages) => $languages === ['en', 'pl'])
            ->map(fn (array $languages, string $key) => "{$key}: only ".implode(',', $languages))
            ->values()
            ->all();
    }

    public function test_every_email_template_exists_in_polish_and_english(): void
    {
        $this->assertSame([], $this->keysMissingALanguage(EmailTemplate::class));
    }

    public function test_every_sms_template_exists_in_polish_and_english(): void
    {
        $this->assertSame([], $this->keysMissingALanguage(SmsTemplate::class));
    }
}
