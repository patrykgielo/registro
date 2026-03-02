# Content Security Policy (CSP) Documentation

**Last Updated:** 2026-02-01
**Location:** `docker/nginx/app.prod.conf` (line 104)

## Overview

Content Security Policy (CSP) is a security mechanism that helps prevent XSS attacks by controlling which resources can be loaded and executed on the page.

## Current CSP Configuration

```
default-src 'self';
script-src 'self' 'unsafe-inline' 'unsafe-eval' https://maps.googleapis.com https://maps.gstatic.com https://cdn.jsdelivr.net https://*.googletagmanager.com https://cdn-cookieyes.com https://*.cookieyes.com;
style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net https://cdn.jsdelivr.net https://cdn-cookieyes.com https://*.cookieyes.com;
img-src 'self' data: blob: https://maps.gstatic.com https://maps.googleapis.com https://*.ggpht.com https://*.googleusercontent.com https://ui-avatars.com https://www.googletagmanager.com https://cdn-cookieyes.com https://*.cookieyes.com;
font-src 'self' data: https://fonts.gstatic.com https://fonts.bunny.net;
connect-src 'self' https://maps.googleapis.com https://*.googleapis.com https://cdn.jsdelivr.net https://*.googletagmanager.com https://*.google-analytics.com https://*.analytics.google.com https://cdn-cookieyes.com https://*.cookieyes.com;
frame-src 'self' https://*.google.com;
worker-src 'self' blob:;
object-src 'none';
frame-ancestors 'none';
base-uri 'self';
form-action 'self';
upgrade-insecure-requests;
```

## Security Directives Explained

| Directive | Value | Purpose |
|-----------|-------|---------|
| `default-src` | `'self'` | Fallback - only allow same-origin resources |
| `object-src` | `'none'` | **Blocks Flash/Java plugins** (security) |
| `frame-ancestors` | `'none'` | **Prevents clickjacking** (security) |
| `base-uri` | `'self'` | **Prevents base tag injection** (security) |
| `form-action` | `'self'` | **Prevents form hijacking** (security) |
| `upgrade-insecure-requests` | - | **Forces HTTPS** for all resources |

## Why 'unsafe-eval' is Required

**Problem:** Removing `'unsafe-eval'` completely breaks the Filament admin panel.

**Root Cause:**
- Filament v4 uses Alpine.js extensively
- Alpine.js parses `x-data`, `x-show`, `x-on` attributes using `eval()`
- Without `'unsafe-eval'`, all Alpine expressions fail with CSP errors

**Evidence (console errors without unsafe-eval):**
```
Alpine Expression Error: Evaluating a string as JavaScript violates CSP
Expression: "$store.sidebar.isOpen"
Expression: "toggle($event)"
Expression: "filamentDropdown"
```

**Solution:** Implement Livewire CSP-safe mode + Alpine.js CSP build (tracked in ClickUp task)

## Why 'unsafe-inline' is Required

**Problem:** Many inline scripts exist in Blade templates.

**Examples:**
- Google Maps dynamic script loading
- Alpine.js initialization in layouts
- Vite hot module replacement (dev)

**Solution:** Implement nonce-based CSP with Spatie Laravel-CSP package (future task)

## External Domains Explained

| Domain | Purpose | Used By |
|--------|---------|---------|
| `maps.googleapis.com` | Google Maps JavaScript API | Booking wizard, Service area picker |
| `maps.gstatic.com` | Google Maps static assets | Map tiles |
| `cdn.jsdelivr.net` | Event Calendar library | Filament calendar widget |
| `fonts.googleapis.com` | Google Fonts CSS | Typography |
| `fonts.bunny.net` | Privacy-friendly fonts | Filament admin (GDPR) |
| `ui-avatars.com` | Avatar generation | User profile pictures |
| `*.ggpht.com` | Google Street View images | Map integration |
| `*.googleusercontent.com` | Google user content | Maps integration |
| `*.googletagmanager.com` | Google Tag Manager | Analytics/GTM container |
| `*.google-analytics.com` | Google Analytics | Analytics data collection |
| `*.analytics.google.com` | Google Analytics 4 | GA4 measurement |
| `cdn-cookieyes.com` | CookieYes CDN (scripts, styles, images) | Cookie banner assets |
| `*.cookieyes.com` | CookieYes API subdomains (log.cookieyes.com, etc.) | Consent logging & API |

### CookieYes: Why Both Domains Are Required

CookieYes uses two separate domains:
- **`cdn-cookieyes.com`** — A standalone domain (not a subdomain of cookieyes.com) that serves the banner script, CSS, and images.
- **`*.cookieyes.com`** — Subdomains like `log.cookieyes.com` used for consent logging and API calls.

The wildcard `*.cookieyes.com` does **not** match `cdn-cookieyes.com` because `cdn-cookieyes.com` is a separate top-level domain (the hyphen is part of the domain name, not a subdomain separator). Both entries must be present in `script-src`, `style-src`, `img-src`, and `connect-src`.

## Testing CSP

### Browser DevTools
1. Open DevTools (F12)
2. Go to Console tab
3. Look for CSP violation messages (red errors)

### Online Tools
- **CSP Evaluator:** https://csp-evaluator.withgoogle.com/
- **Security Headers:** https://securityheaders.com/?q=registro.local

### Command Line
```bash
curl -sI https://registro.local | grep -i content-security-policy
```

## Troubleshooting

| Problem | Likely Cause | Solution |
|---------|--------------|----------|
| Script not loading | Missing from `script-src` | Add domain to whitelist |
| API blocked | Missing from `connect-src` | Add domain to whitelist |
| Image not showing | Missing from `img-src` | Add domain to whitelist |
| Admin panel broken | Missing `'unsafe-eval'` | Restore `'unsafe-eval'` |

## Future Improvements

**ClickUp Task:** [SECURITY] Wdrożenie Nonce-based CSP
**URL:** https://app.clickup.com/t/86c7a19ck

### Roadmap:

1. **Phase 1:** Install Spatie Laravel-CSP package
2. **Phase 2:** Enable Livewire CSP-safe mode
3. **Phase 3:** Refactor inline scripts to external files with nonces
4. **Phase 4:** Remove `'unsafe-inline'` and `'unsafe-eval'`
5. **Phase 5:** Add CSP violation reporting

## Related Documentation

- [ADR-016: Domain Migration](../deployment/ADR-016-domain-migration-registro-pl.md)
- [Security README](./README.md)
- [OWASP CSP Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)

## Version History

| Date | Change | Author |
|------|--------|--------|
| 2025-12-31 | Initial CSP with security directives | Claude Code |
| 2025-12-31 | Added ui-avatars.com, cdn.jsdelivr.net | Claude Code |
| 2026-02-01 | Added GTM, GA domains (v4.27.1 hotfix) | Claude Code |
| 2026-02-01 | Added cdn-cookieyes.com for CookieYes consent (v4.27.2 hotfix) | Claude Code |
| 2026-02-01 | Added cdn-cookieyes.com alongside *.cookieyes.com in all directives (v4.27.4 hotfix) | Claude Code |
