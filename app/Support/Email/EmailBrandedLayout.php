<?php

declare(strict_types=1);

namespace App\Support\Email;

use App\Models\Organization;
use App\Support\Settings\SettingsManager;

/**
 * Wraps a rendered DB-template `html_body` in a shared branded header/footer
 * at send time (ClickUp 123k99cvc56) — the seeded template bodies themselves
 * never change, so a tenant's own override keeps rendering exactly as it
 * always has, just inside the same shell.
 *
 * Reads branding via SettingsManager::emailBrandingFor($organization) —
 * the EXPLICIT-organization accessor, never the ambient get()/currentTenant()
 * family. EmailService runs in a Horizon queue worker with no request
 * context, so the organization must come from the caller (the notification's
 * own Order/notifiable), not from anything resolved implicitly here — see
 * notifications.md's layout-contract section.
 */
class EmailBrandedLayout
{
    public function __construct(
        private readonly SettingsManager $settings
    ) {}

    /**
     * A tenant may fully override html_body with their own complete HTML
     * document (EmailTemplate.html_body is a free-text column editable from
     * the admin panel). Wrapping that a second time would nest <html> inside
     * <html> — detect it and pass the body through untouched instead.
     *
     * $organization is deliberately NON-nullable: the only caller
     * (EmailService::sendFromTemplate()) already guards `$organization !== null`
     * before calling this method at all — null there means "send unwrapped",
     * never "wrap with platform branding" (see notifications.md's layout-contract
     * section for the regression this distinction fixes). Accepting null here
     * too would let a future caller pass it through by mistake and silently get
     * PLATFORM branding via SettingsManager::emailBrandingFor(null) instead of
     * the "no wrapping at all" they meant.
     */
    public function wrap(string $renderedHtmlBody, Organization $organization): string
    {
        if (preg_match('/<html[\s>]/i', $renderedHtmlBody) === 1) {
            return $renderedHtmlBody;
        }

        $branding = $this->settings->emailBrandingFor($organization);
        $contact = $this->settings->contactDetailsFor($organization);

        return view('emails.branded-layout', [
            // Already-rendered template output — EmailTemplate::render() has
            // already HTML-escaped every substituted variable (TrustedHtml
            // rule in notifications.md). The layout must not escape it again
            // (double-escaping) nor strip anything from it (un-escaping) —
            // it is inserted as-is via {!! !!} in the view.
            'bodyHtml' => $renderedHtmlBody,
            'logoUrl' => $branding['logo_url'],
            'brandColor' => $branding['brand_color'] ?? '#e5e7eb',
            'brandName' => $branding['brand_name'],
            'contact' => $contact,
        ])->render();
    }
}
