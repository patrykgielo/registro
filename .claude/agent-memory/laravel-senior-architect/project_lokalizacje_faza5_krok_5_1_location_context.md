---
name: project_lokalizacje_faza5_krok_5_1_location_context
description: Faza 5 krok 5.1 (LocationContext + ShareSelectedLocation) — sesja lokalizacji, host-only cookie finding
metadata:
  type: project
---

2026-09-09, branch `feature/lokalizacje-faza5-kontekst` (ClickUp `86cbahqg3`), niezmergowana.
`App\Support\LocationContext` + `App\Http\Middleware\ShareSelectedLocation` (dopisany do
globalnej grupy `web` w `bootstrap/app.php`, zaraz po `CheckMaintenanceMode`).

**Kluczowe rozstrzygnięcie:** `selectionRequired()` to właściwość TENANTA (liczba aktywnych
lokalizacji: 0/1 → `false`, 2+ → `true`), niezależna od tego, co jest w sesji — nie "czy user
już wybrał". `selected()` sam się rewaliduje przy każdym wywołaniu (nie ufa surowej sesji),
więc jest bezpieczny nawet bez middleware. Middleware istnieje wyłącznie żeby surowa wartość
sesji nie została "duchem" dla przyszłego kodu czytającego ją bezpośrednio.

**Znalezisko przy okazji, zweryfikowane w vendorze (nie założone):** `SESSION_DOMAIN` jest
falsy w KAŻDYM środowisku tego repo (dev: `.env.example` ma literalne `null`, `env()` helper
konwertuje string `"null"` na prawdziwe PHP `null`; prod: `docker-compose.prod.yml` ma pusty
string `""`) — `Symfony\Component\HttpFoundation\Cookie::__toString()` emituje `Domain=` tylko
`if ($this->getDomain())`, a oba są falsy. Ciasteczko sesji jest więc **host-only** wszędzie —
przeglądarka NIE wysyła ciasteczka z jednej subdomeny tenanta na drugą ani na domenę główną.
"Stale session po zmianie subdomeny" (dosłowne zagrożenie ze zgłoszenia) jest więc dziś
strukturalnie niemożliwe przez carry-over ciasteczka — realny wektor to usunięta/dezaktywowana
lokalizacja na TYM SAMYM hoście, co middleware i tak obsługuje identycznie.

Testy: `LocationContextTest` (15, Unit, `$this->app['request']->attributes->set('tenant', ...)`
zamiast pełnego HTTP) + `ShareSelectedLocationTest` (6, Feature, prawdziwy `actingAsTenant()` +
`$this->get('/')` — kryterium akceptacji "obcy tenant → 200 nie 500" musi iść przez prawdziwy
request, nie przez wywołanie klasy w izolacji). Baseline 962 plików/1878 testów → 966/1899,
dokładna zgodność (+4 pliki, +21 testów, 0 failed).

Pełny opis: `app/docs/features/lokalizacje/README.md`, `plan-wdrozenia.md` (Faza 5, krok 5.1).
