---
name: project-content-grid-visibility-and-admin-reset
description: 2026-10-04 branch feature/oddzial-i-reset-hasla — content_grid render-time visibility per model + panel login "forgot password" link
metadata:
  type: project
---

ContentGridResolver::visibleQuery() is the single "public-visible" definition (Service::visibleOnSite() new scope, Post/PortfolioItem::published(), Promotion::activeAndValid(), Location::active()); render filters with it; picker OFFERS ALL tenant rows, not-visible ones marked `NOT_VISIBLE_SUFFIX` (coordinator: preparing pages in advance is a real workflow). Empty result = block renders nothing.

**Surprise worth remembering:** Filament Select(multiple) validates stored state with an `in` rule against its options (`getInValidationRuleValues`), so a picker that only offers visible items makes a page with a since-hidden id UNSAVEABLE with an error naming no item (latent for services/locations before this). Offering not-visible rows (marked) fixes it; a stored id of a DELETED row still blocks save (residual).

Admin/platform login link: `PanelsRenderHook::AUTH_LOGIN_FORM_AFTER` into existing `password.*` flow; Filament `->passwordReset()` rejected (own ResetPassword notification bypasses User::sendPasswordResetNotification -> EmailService). `scoped(LocationContext)` is stale across two requests in one test: `forgetScopedInstances()`.

**Why:** ClickUp 123k99cu26t + 86cbb28m7. **How to apply:** new content type => new arm in visibleQuery + test; see app/docs/features/content-grid-visibility.md and password-reset-flow.md.
