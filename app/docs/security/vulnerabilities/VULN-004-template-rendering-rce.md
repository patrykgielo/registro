# VULN-004: Server-Side Template Injection → RCE in Email/SMS Template Rendering

**Status**: FIXED
**Severity**: CRITICAL
**Priority**: P0
**Detected**: 2026-07-04 (multi-agent security review, `agent-security-audit-specialist` + `code-reviewer`, cross-verified in `tinker` against the pre-fix code)
**Fixed**: 2026-07-05
**Branch**: `fix/template-rendering-rce`

## Problem

`EmailTemplate::render()` and `SmsTemplate::render()` took the tenant-admin-editable
`html_body`/`message_body` columns, did a naive `{{var}}` → `{{ $var }}` regex substitution,
and passed the result directly into `Illuminate\Support\Facades\Blade::render()`.
`Blade::render()` compiles its input as a real Blade view and executes it as PHP — so any
`@php ... @endphp` block, or even a bare Blade expression like `{{ 1+1 }}` (which does not
match the `\w+`-only extraction regex and so reached `Blade::render()` untouched), executed
with full web-server privileges.

Editing these templates only requires the `communication.manage_templates` permission
(`database/seeders/RolePermissionSeeder.php`), which is granted to the ordinary tenant
**admin** role — an everyday paying customer, not a super-admin. Any such tenant could embed
a payload in an email/SMS template (e.g. the appointment-confirmation template) and get
arbitrary PHP execution on the shared application server the next time that template was
used to send a message (`EmailService`/`SmsService::renderTemplate()` render automatically on
every send). Since one server hosts all tenants, this fully compromised tenant isolation —
an attacker could read every other organization's database credentials, `.env` secrets, and
customer PII from a single low-privileged account.

## Przyczyna (Root Cause)

`render()` treated the stored template body as a template *language* to compile, when it only
ever needed placeholder *substitution*. The regex-based `{{var}}` → `{{ $var }}` step was
meant to make plain `{{variable}}` syntax "just work" with Blade, but it did nothing to
sanitize or reject the rest of the body — any valid Blade syntax the admin wrote (directives,
expressions, function calls) compiled and ran unchanged.

## Rozwiązanie (Fix)

Both `EmailTemplate::render()` (`app/Models/EmailTemplate.php`) and `SmsTemplate::render()`
(`app/Models/SmsTemplate.php`) now use a shared `substitutePlaceholders()` helper:

```php
return preg_replace_callback('/\{\{(\w+)\}\}/', function (array $matches) use ($data, $escape) {
    $key = $matches[1];
    if (! array_key_exists($key, $data)) {
        return $matches[0];
    }
    $value = (string) $data[$key];
    return $escape ? e($value) : $value;
}, $template);
```

This never invokes `Blade::render()`, `Blade::compileString()`, `eval()`, or any other
compiler — only literal `{{key}}` tokens are substituted; everything else in the body
(`@php`, `@if`, `{{ 1+1 }}`, raw `<?php`) is copied through as inert literal text.
`EmailTemplate` HTML-escapes substituted values (`escape: true`, since `html_body` renders as
email HTML); `SmsTemplate` does not (plain text, escaping would corrupt the message).

Verified via two independent reviews:
- **code-reviewer**: confirmed happy-path parity with the old Blade `{{ $var }}` behavior
  (Blade's default echo also runs through `e()`), confirmed the new tests would have failed
  against the old code.
- **agent-security-audit-specialist**: reproduced the original RCE in `tinker` against the
  pre-fix logic (`@php file_put_contents(...)` actually wrote a file; `{{ 1+1 }}` rendered as
  `2`, proving the vulnerability wasn't limited to `@php`), then grepped the whole `app/` tree
  and confirmed no other call site pipes template-body content through Blade/`eval`.

Also fixed while in this area (found by the same reviews):
- Removed now-unused `use Illuminate\Support\Facades\Blade;` imports from `EmailService`/
  `SmsService` and corrected stale docblocks/admin helper text that claimed Blade support.
- Deleted `resources/views/filament/resources/email-template/preview.blade.php` — an
  orphaned, unreferenced view rendering `{!! $rendered !!}` (raw, unescaped) that would have
  reopened this exact class of issue if a live-preview feature was ever wired up to it.

New tests: `tests/Unit/Models/EmailTemplateTest.php`, `tests/Unit/Models/SmsTemplateTest.php`
— assert a body containing `@php ... @endphp` renders as literal text (and never executes:
the test asserts the target file is never written), `{{ 1+1 }}` is not evaluated, and normal
`{{key}}` substitution + HTML-escaping still works.

## Zapobieganie (Prevention)

- **Never pass stored user/admin-editable content through `Blade::render()`,
  `Blade::compileString()`, or `eval()`** — not even after "sanitizing" it with a regex.
  Regex-based sanitization of a Turing-complete template language is not a security boundary;
  the only safe fix is to not compile the content at all.
- Any future "live preview" feature for these templates must render through the same
  non-executing `substitutePlaceholders()` path, and if displayed in an iframe, follow the
  `EmailSendResource` pattern (`{{ e(...) }}` inside `srcdoc`, sandboxed, no `allow-scripts`) —
  never `{!! $rendered !!}` directly into a parent view.
- Full suite baseline after this fix: 787 passed, 3 pre-existing unrelated failures
  (`CustomerOrdersTest` ×2, `TenantFeatureTest` ×1), 5 skipped — unchanged from before.

**Related**: found during a broader multi-agent security review (2026-07-04, 13 review
domains, 154 agent runs) alongside [VULN-003](VULN-003-root-domain-tenant-bypass.md)
follow-up verification.
