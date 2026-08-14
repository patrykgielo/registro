<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\EmailTemplate;
use App\Support\Email\TrustedHtml;
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

    public function test_render_inserts_trusted_html_verbatim_instead_of_escaping_it(): void
    {
        $template = new EmailTemplate([
            'key' => 'trusted-html-template',
            'language' => 'pl',
            'subject' => 'Subject',
            'html_body' => '<p>{{items_list_html}}</p>',
        ]);

        $rendered = $template->render([
            'items_list_html' => new TrustedHtml('<table><tr><td>Wiertarka</td></tr></table>'),
        ]);

        $this->assertSame('<p><table><tr><td>Wiertarka</td></tr></table></p>', $rendered);
    }

    /**
     * The allowlist is per-VALUE (TrustedHtml wrapper), not per variable name — a plain string
     * under the same key name a notification would use for markup is still escaped like any
     * other value. Nothing about the *name* "items_list_html" is special to EmailTemplate.
     */
    public function test_render_still_escapes_a_script_tag_arriving_through_an_unwrapped_value(): void
    {
        $template = new EmailTemplate([
            'key' => 'unwrapped-value-template',
            'language' => 'pl',
            'subject' => 'Subject',
            'html_body' => '<p>{{items_list_html}}</p>',
        ]);

        $rendered = $template->render([
            'items_list_html' => '<script>alert(1)</script>',
        ]);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $rendered);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $rendered);
    }

    /**
     * renderSubject()/renderText() have no legitimate use for markup — a TrustedHtml value
     * reaching either has its tags stripped rather than emitted raw, so an accidental reuse of
     * an HTML-bearing variable in a plain-text/subject template can never leak markup there.
     */
    public function test_subject_and_text_strip_tags_from_a_trusted_html_value_instead_of_emitting_them(): void
    {
        $template = new EmailTemplate([
            'key' => 'trusted-html-in-plain-context',
            'language' => 'pl',
            'subject' => 'Subject {{items_list_html}}',
            'html_body' => '<p>{{items_list_html}}</p>',
            'text_body' => 'Text {{items_list_html}}',
        ]);

        $data = ['items_list_html' => new TrustedHtml('<table><tr><td>Wiertarka</td></tr></table>')];

        $this->assertSame('Subject Wiertarka', $template->renderSubject($data));
        $this->assertSame('Text Wiertarka', $template->renderText($data));
    }

    /**
     * A CR/LF pair inside a substituted value (e.g. a customer name at checkout) must not
     * survive into the rendered subject — this is a header-injection vector for any mailer
     * that does not neutralise it, unlike Symfony Mime today (defence-in-depth, see
     * .claude/rules/notifications.md).
     */
    public function test_render_subject_strips_control_characters_from_a_substituted_value(): void
    {
        $template = new EmailTemplate([
            'key' => 'order-notification',
            'language' => 'pl',
            'subject' => 'Zamówienie {{n}}',
        ]);

        $rendered = $template->renderSubject(['n' => "123\r\nBcc: ofiara@example.com"]);

        $this->assertMatchesRegularExpression('/\A[^\x00-\x1F\x7F]*\z/', $rendered);
        $this->assertSame('Zamówienie 123 Bcc: ofiara@example.com', $rendered);
    }

    /**
     * Sanitizing must happen on the FINAL rendered string, not per-value — a newline pasted
     * directly into the subject TEMPLATE by a tenant admin (via EmailTemplateResource) is the
     * same injection vector and a per-value fix would miss it entirely.
     */
    public function test_render_subject_strips_control_characters_embedded_in_the_template_itself(): void
    {
        $template = new EmailTemplate([
            'key' => 'admin-edited-template',
            'language' => 'pl',
            'subject' => "Zamówienie #{{order_number}}\r\nBcc: ofiara@example.com",
        ]);

        $rendered = $template->renderSubject(['order_number' => '456']);

        $this->assertMatchesRegularExpression('/\A[^\x00-\x1F\x7F]*\z/', $rendered);
        $this->assertSame('Zamówienie #456 Bcc: ofiara@example.com', $rendered);
    }

    /**
     * A run of consecutive control characters collapses to a single space, not one space per
     * character — otherwise a double CRLF (blank line, a common header-injection separator)
     * would leave a visually broken run of spaces in the subject.
     */
    public function test_render_subject_collapses_a_run_of_control_characters_to_a_single_space(): void
    {
        $template = new EmailTemplate([
            'key' => 'collapse-template',
            'language' => 'pl',
            'subject' => '{{value}}',
        ]);

        $rendered = $template->renderSubject(['value' => "a\r\n\r\nb"]);

        $this->assertSame('a b', $rendered);
    }

    /**
     * A legitimate Polish subject — diacritics, punctuation, an order number — must survive
     * renderSubject() byte-for-byte. Guards against the sanitizer being overbroad (e.g.
     * mangling multi-byte UTF-8 sequences it should never touch).
     */
    public function test_render_subject_leaves_a_legitimate_polish_subject_unchanged(): void
    {
        $template = new EmailTemplate([
            'key' => 'legit-template',
            'language' => 'pl',
            'subject' => 'Potwierdzenie zamówienia #{{order_number}} – dziękujemy, Państwu Kowalskim!',
        ]);

        $rendered = $template->renderSubject(['order_number' => 'ZAM-0042']);

        $this->assertSame(
            'Potwierdzenie zamówienia #ZAM-0042 – dziękujemy, Państwu Kowalskim!',
            $rendered
        );
    }

    /**
     * renderText() must NOT get the same treatment — a plain-text body legitimately contains
     * newlines as paragraph/line breaks. Guards against over-applying the subject fix.
     */
    public function test_render_text_still_preserves_newlines_in_a_plain_text_body(): void
    {
        $template = new EmailTemplate([
            'key' => 'text-body-template',
            'language' => 'pl',
            'subject' => 'Subject',
            'text_body' => "Linia pierwsza\r\nLinia druga {{name}}",
        ]);

        $rendered = $template->renderText(['name' => 'Jan']);

        $this->assertSame("Linia pierwsza\r\nLinia druga Jan", $rendered);
    }
}
