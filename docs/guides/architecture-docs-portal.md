# Architecture Documentation Portal (MkDocs + D2)

A self-hosted, always-current documentation website that renders this
`app/docs/` tree live (via [MkDocs Material](https://squidfunk.github.io/mkdocs-material/))
and can render selected diagrams as polished [D2](https://d2lang.com) SVGs
alongside the existing Mermaid diagrams. No content duplication — MkDocs
points directly at `app/docs/` as its `docs_dir`. No CI/CD dependency, no
third-party SaaS: everything runs locally via a dedicated Docker Compose
file and rebuilds automatically whenever a source file changes.

This is entirely separate from the application's own Docker Compose stack
(`docker-compose.yml` / `.dev.yml` / `.staging.yml` / `.prod.yml`) — it does
not touch, extend, or depend on any of those files or services.

## Starting it

```bash
docker compose -f docker-compose.docs.yml up -d --build
```

## Viewing it

Open **http://localhost:8090**.

### If you browse it from another machine (VPN / Tailscale / LAN)

`site_url` must match the address you actually type in the browser, including
the port. MkDocs bakes it into the generated sitemap and the language
switcher's links — if it says `localhost` but you reached the portal over a
Tailscale IP, the switcher lands on the wrong host and silently 404s.

Because that address is per-machine, it is **not** committed. Set it in the
repo's gitignored `.env`:

```bash
DOCS_SITE_URL=http://100.x.y.z:8090/
```

Then restart the stack (`docker compose -f docker-compose.docs.yml up -d`).
With the variable unset it falls back to `http://localhost:8090/`, which is
correct when you browse from the same machine that runs the containers.

Wired up as `site_url: !ENV [DOCS_SITE_URL, 'http://localhost:8090/']` in
`docs-site/mkdocs.yml`, fed by the `environment:` block on `docs-builder` in
`docker-compose.docs.yml`.

## Stopping it

```bash
docker compose -f docker-compose.docs.yml down
```

(Add `-v` to also drop the `docs_output` named volume holding the built
site, if you want a fully clean slate on next start.)

## How auto-rebuild works

- `docs-builder` (service) builds a small Python 3.12 image with
  `mkdocs-material`, the D2 CLI, and `entr`.
- `docs-site/watch.sh` runs `docs-site/build.sh` once immediately, then
  re-runs it via `entr -nr` every time a file under `app/docs/` or
  `docs-site/` changes. The generated `docs-site/site/` output dir and
  generated `.svg` files are excluded from the watch list so the build
  writing its own output doesn't re-trigger itself.
- `docs-site/build.sh`:
  1. Compiles every `*.d2` file under `app/docs/` and `docs-site/` to an
     adjacent `.svg` via the `d2` CLI (per-file errors are logged, not
     fatal — one broken diagram doesn't break the whole build).
  2. Runs `mkdocs build --site-dir /workspace/docs-site/site`.
  3. Logs a timestamped line, visible via
     `docker compose -f docker-compose.docs.yml logs docs-builder`.
- `docs-nginx` (service) serves the built `site/` output (shared volume
  `docs_output`) on port **8090**.

Both `app/docs/` and `docs-site/` are bind-mounted into the `docs-builder`
container, so editing a Markdown file or a `.d2` diagram on the host
triggers a rebuild within a few seconds — no manual export step required.

## Adding a new D2 diagram

1. Create a `.d2` file anywhere under `app/docs/` (convention used here:
   `app/docs/architecture/diagrams/<name>.d2`).
2. The next auto-rebuild compiles it to `<name>.svg` in the same directory.
3. Embed it in a Markdown page: `` ![Alt text](diagrams/<name>.svg) ``.
4. Add the page to `docs-site/mkdocs.yml`'s `nav:` if it should appear in
   the sidebar.

See `app/docs/architecture/diagrams/panel-isolation.d2` and
`app/docs/architecture/panel-isolation-d2-demo.md` for a worked example —
a client-presentable D2 rendering of the panel isolation architecture
(verified against `AdminPanelProvider.php`, `PlatformPanelProvider.php`,
`ResolveTenant.php`, `RequireTenant.php`, `BaseResource.php`), alongside the
project's existing Mermaid diagrams.

## Known deviations from a "textbook" setup

- `mkdocs build` runs **without** `--strict`. The existing `app/docs/`
  tree (accumulated over many months, largely unrelated to this portal)
  has ~200 pre-existing broken in-page anchor links and missing-file
  references. Fixing those is a content/docs-hygiene task for whoever owns
  each doc, not something this DevOps-owned build pipeline should silently
  paper over or block on. `mkdocs build` output still prints every warning
  to `docker compose logs docs-builder` so they remain visible.
- `entr` requires `-n` (non-interactive) inside a container without a TTY,
  otherwise it exits immediately with `unable to get terminal attributes`.
- **Mermaid.js is vendored locally** at `app/docs/assets/vendor/mermaid.min.js`
  (the UMD build, not the ESM one — required for a plain `<script>` tag,
  and the same distinction that matters for the offline single-HTML-file
  viewer described elsewhere in project memory). The initial implementation
  referenced `https://unpkg.com/mermaid@10/...` directly, which silently
  reintroduced a runtime internet dependency into an otherwise fully
  self-hosted portal — fixed by fetching the package once (`npm pack
  mermaid`) and committing the resulting `.min.js` file, so the browser
  never needs outbound internet access to render a Mermaid diagram.
- **`docker-compose.docs.yml` sets an explicit `name: registro-docs`.**
  Without it, Compose derives the project name from the current directory
  (`app`), which collides with the main application stack's project name
  — Compose then treats `registro-mysql`/`registro-horizon`/etc. as
  "orphan containers" of *this* file. This was observed directly (a real
  warning on `docker compose -f docker-compose.docs.yml up`, not
  hypothetical) before the fix — a `down --remove-orphans` run in that
  state could have stopped the real application stack. Same class of
  mistake as the dual-queue-worker incident documented in
  `.claude/rules/ci-cd-troubleshooting.md`.

## Git hook integration (best-effort, non-blocking)

`.githooks/post-merge` includes an optional, best-effort step: if the
`docs-builder` container is currently running, a merge triggers one
rebuild pass immediately (so the portal reflects `develop` right after a
merge, without waiting for the next file-change event). If the docs stack
isn't running, this step is a silent no-op — it never blocks or fails a
merge.
