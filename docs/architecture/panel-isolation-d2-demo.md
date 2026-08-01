# Izolacja paneli — diagram D2 (wersja prezentowalna dla klienta)

Ta strona prezentuje pipeline diagramów D2 (Terrastruct) dodany przez portal
dokumentacji architektury (`docs-site/`). Poniższy diagram przedstawia tę samą
architekturę izolacji paneli (panel tenantowy `/admin` vs panel super-admina
`/platform`, middleware `ResolveTenant`/`RequireTenant`, gating modułów
`BaseResource`), którą reszta tego zestawu dokumentacji opisuje w formie
Mermaid — to bardziej dopracowana wizualnie, prezentowalna dla klienta
wersja tych samych faktów, wygenerowana z pliku
`architecture/diagrams/panel-isolation.d2` i automatycznie skompilowana do SVG
przez pipeline budowania dokumentacji.

Diagramy takie jak ten istnieją po to, by móc je udostępniać potencjalnym
klientom podczas rozmów sprzedażowych lub onboardingowych bez ujawniania
surowej składni Mermaid ani pełnego wewnętrznego drzewa dokumentacji — to samo
źródło prawdy, lepsza prezentacja.

![Panel Isolation](diagrams/panel-isolation.svg)

**Źródło:** [`architecture/diagrams/panel-isolation.d2`](diagrams/panel-isolation.d2)
— zweryfikowane względem `AdminPanelProvider.php`, `PlatformPanelProvider.php`,
`ResolveTenant.php`, `RequireTenant.php`, `BaseResource.php` (2026-07).

Zobacz również: `guides/architecture-docs-portal.md` — jak dodać nowy diagram D2
do tego pipeline'u.
