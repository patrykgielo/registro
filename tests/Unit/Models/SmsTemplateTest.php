<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\SmsTemplate;
use Tests\TestCase;

class SmsTemplateTest extends TestCase
{
    public function test_render_substitutes_known_placeholders(): void
    {
        $template = new SmsTemplate([
            'key' => 'test-template',
            'language' => 'pl',
            'message_body' => 'Czesc {{user_name}}, Twoja rezerwacja: {{missing}}',
            'max_length' => 160,
        ]);

        $rendered = $template->render(['user_name' => 'Jan']);

        $this->assertSame('Czesc Jan, Twoja rezerwacja: {{missing}}', $rendered);
    }

    public function test_render_never_executes_blade_directives_embedded_in_template_body(): void
    {
        $template = new SmsTemplate([
            'key' => 'malicious-template',
            'language' => 'pl',
            'message_body' => 'Hi {{user_name}} @php file_put_contents(storage_path("app/pwned.txt"), "pwned"); @endphp',
            'max_length' => 500,
        ]);

        $rendered = $template->render(['user_name' => 'Jan']);

        $this->assertStringContainsString('@php', $rendered);
        $this->assertStringContainsString('@endphp', $rendered);
        $this->assertFileDoesNotExist(storage_path('app/pwned.txt'));
    }

    public function test_render_does_not_evaluate_php_expressions_inside_braces(): void
    {
        $template = new SmsTemplate([
            'key' => 'expr-template',
            'language' => 'pl',
            'message_body' => 'Result: {{ 1+1 }}',
            'max_length' => 160,
        ]);

        $rendered = $template->render([]);

        $this->assertStringContainsString('{{ 1+1 }}', $rendered);
        $this->assertStringNotContainsString('Result: 2', $rendered);
    }
}
