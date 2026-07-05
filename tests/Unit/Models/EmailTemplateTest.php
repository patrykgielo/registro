<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\EmailTemplate;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    public function test_render_substitutes_known_placeholders_and_html_escapes_values(): void
    {
        $template = new EmailTemplate([
            'key' => 'test-template',
            'language' => 'pl',
            'subject' => 'Subject {{app_name}}',
            'html_body' => '<h1>Witaj {{user_name}}!</h1><p>{{missing}}</p>',
        ]);

        $rendered = $template->render([
            'user_name' => '<script>alert(1)</script>',
        ]);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $rendered);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $rendered);
        // Static markup around the placeholder is preserved untouched.
        $this->assertStringContainsString('<h1>Witaj', $rendered);
        // Unknown token (not present in $data) is left as literal text.
        $this->assertStringContainsString('{{missing}}', $rendered);
    }

    public function test_render_never_executes_blade_directives_embedded_in_template_body(): void
    {
        $template = new EmailTemplate([
            'key' => 'malicious-template',
            'language' => 'pl',
            'subject' => 'Subject',
            'html_body' => 'Hello {{user_name}} @php file_put_contents(storage_path("app/pwned.txt"), "pwned"); @endphp',
        ]);

        $rendered = $template->render(['user_name' => 'Jan']);

        // The @php directive must survive as literal text — never compiled/executed.
        $this->assertStringContainsString('@php', $rendered);
        $this->assertStringContainsString('@endphp', $rendered);
        $this->assertFileDoesNotExist(storage_path('app/pwned.txt'));
    }

    public function test_render_does_not_evaluate_php_expressions_inside_braces(): void
    {
        $template = new EmailTemplate([
            'key' => 'expr-template',
            'language' => 'pl',
            'subject' => 'Subject',
            'html_body' => 'Result: {{ 1+1 }}',
        ]);

        $rendered = $template->render([]);

        // Not a valid {{key}} token (contains non-word chars) — left untouched, not evaluated to "2".
        $this->assertStringContainsString('{{ 1+1 }}', $rendered);
        $this->assertStringNotContainsString('Result: 2', $rendered);
    }

    public function test_render_text_and_subject_still_substitute_normally(): void
    {
        $template = new EmailTemplate([
            'key' => 'normal-template',
            'language' => 'pl',
            'subject' => 'Witamy {{user_name}}',
            'html_body' => '<p>Witaj {{user_name}}</p>',
            'text_body' => 'Witaj {{user_name}}',
        ]);

        $data = ['user_name' => 'Jan Kowalski'];

        $this->assertSame('Witamy Jan Kowalski', $template->renderSubject($data));
        $this->assertSame('Witaj Jan Kowalski', $template->renderText($data));
        $this->assertSame('<p>Witaj Jan Kowalski</p>', $template->render($data));
    }
}
