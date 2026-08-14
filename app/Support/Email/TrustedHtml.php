<?php

declare(strict_types=1);

namespace App\Support\Email;

/**
 * Marks a value as pre-escaped HTML markup that `EmailTemplate::render()` may insert into
 * `html_body` verbatim, bypassing the `e()` call it applies to every other substituted value.
 *
 * `html_body` is editable by tenant-level admins (Filament `EmailTemplateResource`), so
 * `EmailTemplate::render()` HTML-escapes every substituted value by default — a template author
 * cannot make a variable render as markup. This type is the one sanctioned exception, and it is
 * opt-in per VALUE, not per variable NAME: only OUR notification code decides a given string is
 * safe to wrap, at the exact point it finishes building that string. There is no allowlist of
 * variable names living in `EmailTemplate` for this to drift out of sync with — the trust
 * decision travels with the value itself.
 *
 * Wrap a value ONLY when every piece of dynamic content inside it has already been escaped by
 * the code building the string — see OrderPaidNotification::buildRentalVariables() for the
 * pattern: htmlspecialchars() on each interpolated field (e.g. a tenant-editable service name),
 * applied BEFORE concatenation into the surrounding markup, not after. Never wrap a value that
 * embeds unescaped user/tenant input; that reopens the stored-XSS vector this type exists to
 * close. Wrapping does nothing to a value's content — it only changes how EmailTemplate treats
 * it, so the safety property must already hold before construction.
 *
 * Reaching `renderSubject()`/`renderText()` (plain subject line, plain-text body — no legitimate
 * markup in either) strips tags instead of emitting them raw; see
 * EmailTemplate::substitutePlaceholders().
 */
final class TrustedHtml
{
    public function __construct(public readonly string $html) {}
}
