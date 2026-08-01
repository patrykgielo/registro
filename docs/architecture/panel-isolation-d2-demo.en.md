# Panel Isolation — D2 Diagram (client-presentable version)

This page demonstrates the D2 (Terrastruct) diagram pipeline added by the
architecture-docs portal (`docs-site/`). The diagram below shows the same
panel isolation architecture (`/admin` tenant panel vs `/platform` super-admin
panel, `ResolveTenant`/`RequireTenant` middleware, `BaseResource` module
gating) that the rest of this documentation set describes in Mermaid form
elsewhere — this is the more visually-polished, client-presentable rendering
of the identical facts, generated from `architecture/diagrams/panel-isolation.d2`
and compiled to SVG automatically by the docs build pipeline.

Diagrams like this exist to be shared with prospects/clients during sales or
onboarding calls without exposing raw Mermaid syntax or the full internal docs
tree — same source of truth, better presentation.

![Panel Isolation](diagrams/panel-isolation.svg)

**Source:** [`architecture/diagrams/panel-isolation.d2`](diagrams/panel-isolation.d2)
— verified against `AdminPanelProvider.php`, `PlatformPanelProvider.php`,
`ResolveTenant.php`, `RequireTenant.php`, `BaseResource.php` (2026-07).

See also: `guides/architecture-docs-portal.md` for how to add a new D2 diagram
to this pipeline.
