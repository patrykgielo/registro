# Podział obrazu: środowisko osobno, aplikacja osobno — plan

**Status:** Faza 1 (podział i pomiar) zaimplementowana i zweryfikowana lokalnie, PR
`feature/base-image-split-phase1`. **Nie wdrożone na UAT** — `Dockerfile` w tej gałęzi pinuje
`ghcr.io/patrykgielo/registro-base:sha-87912b1fea29`, obraz zbudowany i przetestowany wyłącznie
lokalnie (workflow `build-base-image.yml` nigdy nie dispatchowany na GitHubie — zakaz w tej sesji).
Zanim ta gałąź zostanie zmergowana i wydana, ktoś z dostępem do Actions musi realnie odpalić ten
workflow, żeby ten sam tag istniał w GHCR.
**Data planu:** 2026-08-16. **Data realizacji Fazy 1:** 2026-08-16.
**Powód:** każde wydanie kompiluje od nowa rozszerzenia PHP, mimo że zmieniają się rzędu razy w roku.

---

## 1. Pomiary — skąd wiemy, że to warto

Build lokalny, jeden przebieg każdy, ta sama maszyna:

| | Czas |
|---|---|
| build na zimno (`--no-cache`) | **2 min 43 s** |
| build z ciepłym cache'em | **4,5 s** |

Redukcja o **97%**, gdyby cache działał. Nie działa — patrz sekcja 2.

Rozbicie buildu na zimno (`--progress=plain`):

| Etap | Czas | Klasa |
|---|---|---|
| `docker-php-ext-install` (gd, pdo_mysql, intl, opcache…) | **100,4 s** | środowisko |
| `apt-get install` | 14,4 s | środowisko |
| `pecl install redis` | 14,2 s | środowisko |
| `groupadd`/`useradd`/`chown` (ADR-013, UID 1000) | 6,7 s | środowisko |
| `composer install --no-dev` | 7,2 s | aplikacja |
| `npm ci` | 4,8 s | aplikacja |
| `npm run build` | 3,3 s | aplikacja |
| `composer dump-autoload --optimize` | 2,1 s | aplikacja |
| `COPY . .` | 0,6 s | aplikacja |
| eksport OCI | 16,7 s | mieszane |

**Środowisko: 135,7 s (83%). Aplikacja: 18,0 s (11%).** Eksport 16,7 s (6%) zależy od rozmiaru obrazu, więc częściowo też od środowiska.

Sama kompilacja rozszerzeń PHP to **62% całego buildu**.

W CI job `build` trwa **3 min 29 s – 3 min 38 s** (zmierzone na `v0.13.0-rc15` i `rc16`). Od
dodania kroku Trivy (2026-08-16, patrz `ci-cd-troubleshooting.md`, wpis "composer audit + skan
obrazu") urósł do **~4,2 min** — skan sam w sobie nie jest kosztem tego podziału i Faza 1 go nie
dotyka, ale jest to aktualny punkt odniesienia dla przyszłych pomiarów tego joba.

---

## 2. Dlaczego cache warstw nie ratuje sytuacji

Dodaliśmy `cache-from`/`cache-to` typu `gha`. Po dwóch kolejnych wydaniach: **zero trafień `CACHED`**, czas bez zmian.

Przyczyna, potwierdzona w dokumentacji GitHuba:

> „Workflow runs also cannot restore caches created for different tag names."

Sprawdzone dodatkowo empirycznie: `gh run list --json headBranch` pokazuje, że **referencją każdego przebiegu jest sam tag wydania**, nie gałąź. Czyli każdy nowy tag dostaje własny, pusty zakres cache'u.

**To nie jest problem rozgrzewki. To trwała właściwość naszego modelu wydawania** — dopóki wydajemy tagami, `type=gha` będzie martwy. Komentarz w `deploy-production.yml` opisujący to jako „do sprawdzenia po dwóch wdrożeniach" jest już nieaktualny: sprawdzone, wynik negatywny.

`type=gha` nie szkodzi (`ignore-error=true`), ale nic nie robi.

---

## 3. Co gdzie trafia

### `registro-base`
System, `apt-get`, skompilowane rozszerzenia PHP, `pecl`, użytkownik `laravel` z UID 1000 (ADR-013), binarka Composera. **Zero kodu aplikacji.**

### `registro` (aplikacja)
`FROM registro-base:<tag>`, dalej: `composer install`, build frontendu, `COPY . .`, `dump-autoload`, entrypoint.

Etap `frontend-builder` (`node:20-alpine`) **zostaje w obrazie aplikacji** — `npm ci` i build to razem 8,1 s i zależą od kodu, nie od środowiska. Przeniesienie ich do bazy nic nie kupuje, a zmusza do przebudowy bazy przy każdej zmianie zależności frontendu.

Oczekiwany czas buildu aplikacji: **35–40 s** (18 s pracy + ~17 s eksportu).

**Zmierzone w Fazie 1: 34,459 s** (`--no-cache` na warstwach aplikacji, baza już lokalnie obecna,
`OPCACHE_MODE=production`, `BROWSER_TESTING=false`) — poniżej progu zaliczenia (< 60 s) i zgodne z
oczekiwaniem sprzed implementacji. Pełny log: `composer install` + `check-platform-reqs` (nowy
strażnik, Faza 2 z tego planu wprowadzona już w Fazie 1 — patrz sekcja 6.1) + `COPY . .` +
`dump-autoload` + `chown` + eksport OCI, RAZEM z pełnym `npm ci`+`npm run build` frontend-buildera
(też bez cache'u w tym pomiarze, bo `--no-cache` obejmuje CAŁY plik, obie stage'y).

### 3.1 Gdzie wylądowały `ARG OPCACHE_MODE` i `ARG BROWSER_TESTING`

Obie zostały w `Dockerfile` aplikacji, nie w `Dockerfile.base`. Nie jest to przeniesienie
bezmyślne — poniżej uzasadnienie osobno dla każdej.

**`OPCACHE_MODE`:** wybiera, który z dwóch STATYCZNYCH plików `.ini` skopiować
(`opcache-dev.ini`/`opcache-prod.ini`) — to `cp`, nie kompilacja, kosztuje ułamek sekundy.
Zapieczenie w bazie wymagałoby DWÓCH wariantów bazy (dev/prod) dla zerowej korzyści czasowej, a
`docker-compose.yml` (lokalnie `OPCACHE_MODE=dev`) i CI/produkcja (`OPCACHE_MODE=production`)
musiałyby wybierać między TAGAMI bazy zamiast argumentem builda — droższa zmiana, żadnego zysku.

**`BROWSER_TESTING`:** technicznie JEST pracą środowiskową (apt-get, systemowe biblioteki Chromium)
— naiwna odpowiedź brzmiałaby "to środowisko, przenieś do bazy". Odrzucone z dwóch powodów:

1. Binarki Playwrighta (Chromium) są PRZYPIĘTE do wersji z `package.json` tego repo — kodu, którego
   przy budowaniu `Dockerfile.base` jeszcze nie ma. Przeniesienie samego `apt-get` (biblioteki
   systemowe) do bazy zostawiłoby krok zależny od kodu w aplikacji i tak — zero uproszczenia, za to
   DRUGI wariant bazy do budowania, tagowania i utrzymywania w nieskończoność.
2. Ta flaga jest wyłącznie dev/E2E — ani `test.yml`, ani `deploy-production.yml`
   (`build-args: OPCACHE_MODE=production` i nic więcej) nigdy jej nie ustawiają. Nie była częścią
   zmierzonych 135,7 s/83% "środowiska" z sekcji 1, które uzasadniają cały ten podział — przeniesienie
   jej nie skróciłoby ścieżki, dla której ta zmiana istnieje.

Skutek: **jeden wariant `registro-base`**, nie dwa. Gdyby w przyszłości pomiar pokazał, że sam
`apt-get install` dla Chromium jest kosztowny i uruchamiany często (np. CI zaczyna regularnie
budować obrazy E2E) — rewizja tej decyzji jest tania: to jeden warunkowy blok do przeniesienia, bez
zmiany reszty podziału.

---

## 4. Konfiguracja — nie zmienia się nic

To pytanie pada najczęściej przy takim podziale, więc odpowiedź wprost, potwierdzona w kodzie:

**Konfiguracja nie jest zapiekana w żadnym obrazie.** `Dockerfile` nie wywołuje `artisan` ani razu. `docker/entrypoint.sh` uruchamia `config:cache`, `route:cache` i `view:cache` **przy starcie kontenera** (linie 310–312), a wartości pochodzą ze zmiennych środowiskowych wstrzykiwanych przez `docker-compose.prod.yml` (kotwica `x-app-env`) z pliku `.env` na serwerze.

Ani `registro-base`, ani `registro` nie wie nic o `APP_KEY`, domenie czy haśle do bazy. **Granica jest już postawiona poprawnie i podział jej nie dotyka.**

---

## 5. Kiedy przebudowuje się baza i jak to wykrywać

Wejścia bazy: wersja PHP, lista pakietów `apt`, lista rozszerzeń, konfiguracja `pecl`, ustawienia użytkownika.

**Wykrywanie musi być mechaniczne.** Skrót SHA-256 z `Dockerfile.base`; baza tagowana tym skrótem, np. `registro-base:sha-4f2a91c8e0d3`.

`Dockerfile` aplikacji pinuje **konkretny tag**, nigdy `latest`. Bez tego ten sam commit aplikacji zbudowany dwa razy mógłby dostać różne środowiska, co odbiera nam odtwarzalność — czyli jedyną rzecz, dla której w ogóle budujemy obrazy.

Zmiana bazy staje się wtedy **jawną, recenzowalną zmianą jednej linii** w `Dockerfile` aplikacji.

---

## 6. Integralność — trzy ryzyka

### 6.1 Rozjazd wymagań (obsługiwalne)
`composer.lock` może przynieść pakiet wymagający rozszerzenia, którego baza nie ma. Dziś dowiedzielibyśmy się przy budowaniu; po podziale — **przy starcie kontenera na serwerze**.

Strażnik: `composer check-platform-reqs` w buildzie aplikacji. To wbudowana komenda Composera, która porównuje wymagania platformy (wersja PHP, rozszerzenia) ze środowiskiem, w którym działa. Uruchomiona po `composer install` **wywali build**, zamiast przepuścić obraz, który padnie dopiero na produkcji.

### 6.2 Rollback (obsługiwalne regułą)
Cofnięcie aplikacji do starszego tagu wymaga bazy, na której ją zbudowano. Działa, **dopóki nie kasujemy starych obrazów bazowych**. To dodatkowa reguła dla sprzątania rejestru — dziś `cleanup-cache.yml` celuje w schemat `buildcache-*` i jest bezczynne, ale trzeba pilnować, żeby przyszłe sprzątanie nie objęło `registro-base`.

### 6.3 Łatki bezpieczeństwa (realny koszt, nie tylko ryzyko)
Dziś `FROM php:8.3-fpm` jest **niezapięty**, a build robi `pull: true`. Każde wydanie po cichu zaciąga aktualizacje bezpieczeństwa Debiana. To spowalnia build — ale to jest **jedyny kanał, którym te łatki do nas trafiają**.

Po zapięciu bazy przestaną przychodzić same.

**To jest wymiana, nie usprawnienie:** szybkość i odtwarzalność za automatyczne łatanie. Uważam ją za korzystną, bo dzisiejsze łatanie jest przypadkowe — zależy od tego, czy akurat wydajemy, więc w tygodniu bez wydania nie ma go wcale. Ale wymaga jawnego zastąpienia: **cyklicznej przebudowy bazy**.

Uwaga: przy cyklicznej przebudowie sam skrót `Dockerfile.base` **nie wystarczy jako tag**, bo treść pliku się nie zmienia, a obraz bazowy owszem. Tag musi nieść też numer kolejny albo datę, np. `sha-4f2a91c8e0d3-20260823`.

---

## 7. Fazy

### Faza 1 — podział i pomiar — WYKONANA (lokalnie), NIEWDROŻONA na UAT

Wydzielone `Dockerfile.base`, zbudowana baza raz lokalnie, tag zapięty w `FROM` `Dockerfile`
aplikacji (`ghcr.io/patrykgielo/registro-base:sha-87912b1fea29`), nowy workflow
`build-base-image.yml` (`workflow_dispatch`, oddzielny od `deploy-production.yml`).

**Warunek zaliczenia z tego planu: zmierzony czas buildu aplikacji poniżej minuty.**
**Zmierzone: 34,459 s.** Zaliczone.

**"Obraz działa na UAT" — NIE zweryfikowane, jawnie.** Zakaz w tej sesji: żaden SSH do serwera,
żaden dispatch workflowów. Co ZOSTAŁO zweryfikowane, realnym uruchomieniem, lokalnie:

- **Baza, build na zimno:** `docker build -f Dockerfile.base .` → **2 min 16,711 s** (system
  packages + `docker-php-ext-install` + `pecl install redis` + composer binary + user
  `laravel:laravel`, bez kodu aplikacji).
- **Aplikacja na gotowej bazie:** `docker build -f Dockerfile --no-cache
  --build-arg OPCACHE_MODE=production --build-arg BROWSER_TESTING=false .` (baza już lokalnie,
  warstwy aplikacji WYMUSZONE bez cache'u) → **34,459 s**, poniżej progu.
- **`composer check-platform-reqs` (nowy strażnik z sekcji 6.1, wprowadzony już w Fazie 1, nie
  odłożony do Fazy 2):** pozytywnie — 19/19 wymagań `success` na prawdziwym `composer.lock` tego
  repo. Negatywnie — dopisanie `"ext-doesnotexist12345": "*"` do `composer.json` wewnątrz
  zbudowanego obrazu i ponowne uruchomienie komendy dało `missing` i **kod wyjścia 2** — potwierdzone,
  że strażnik faktycznie wywala `RUN`, nie tylko wypisuje ostrzeżenie.
- **`php -m` identyczne przed i po**, zestawiony z DZIAŁAJĄCEGO kontenera `registro-app` (obraz
  sprzed podziału) i ze świeżo zbudowanego obrazu Fazy 1 — `diff` bez różnic, plik po plik
  identyczny (48 linii z nagłówkami `[PHP Modules]`/`[Zend Modules]` po obu stronach).
- **Kontener realnie startuje, nie tylko buduje się:** `docker run` (bez `--entrypoint`, prawdziwy
  `ENTRYPOINT`) podłączony do sieci `app_registro` (żeby dosięgnąć prawdziwy `registro-mysql`),
  `DB_HOST=registro-mysql`. Entrypoint przeszedł walidację użytkownika (`✅ Container user:
  laravel`), połączył się z bazą, utworzył symlink `storage`, i wykonał finalne `php artisan
  --version` → `Laravel Framework 12.66.0`. Osobno: domyślne `CMD ["php-fpm"]` uruchomione w tle —
  kontener `Up`, log `fpm is running, pid 1` / `ready to handle connections`.
- **`actionlint`** na `build-base-image.yml` (samodzielnie) → czysto. Uruchomiony też na całym
  `.github/workflows/*.yml` — jedno istniejące, niezwiązane ostrzeżenie `shellcheck` w
  `deploy-production.yml:338` (SC2086), sprzed tej zmiany, nie dotyczy nowego pliku.
- Obrazy testowe posprzątane po sesji (`docker rmi`).

**Czego NIE dowodzi to wszystko:** że `build-base-image.yml` faktycznie wypycha obraz do GHCR z
działającymi uprawnieniami (`packages: write`, `GITHUB_TOKEN`), że pakiet `registro-base` faktycznie
ląduje jako prywatny (oczekiwane z domyślnego zachowania GHCR dla prywatnego repo — NIE
zweryfikowane wprost), ani że produkcyjny `docker compose pull` na UAT poprawnie ściąga oba obrazy
(`registro-base` pośrednio, przy każdym buildzie `registro`, i `registro` bezpośrednio) — żadne z
tych trzech nie da się sprawdzić bez realnego dispatcha na GitHubie lub dostępu do serwera, oba
zakazane w tej sesji.

**Procedura przebudowy bazy (do wykonania przez kogoś z dostępem do Actions, przed mergem tej
gałęzi):**

1. `git diff Dockerfile.base` — upewnij się, że treść jest tym, co ma zostać zbudowane.
2. Dispatch `build-base-image.yml` z `main`/gałęzi zawierającej ten `Dockerfile.base`.
3. Workflow liczy tag jako `sha-<12 pierwszych znaków sha256sum Dockerfile.base>` — identyczny
   algorytm co w tej sesji lokalnie (`sha-87912b1fea29` dla treści na dzień 2026-08-16). Jeśli
   `Dockerfile.base` się nie zmienił, workflow wykrywa istniejący tag przez `docker manifest
   inspect` i NIE buduje ponownie (raportuje to w step summary).
4. Po sukcesie: zaktualizuj `FROM` w `Dockerfile` aplikacji na wypisany tag (workflow tego NIE robi
   automatycznie — patrz Faza 4 poniżej), otwórz PR.
5. Stare tagi bazy NIE są usuwane automatycznie przez nic w tym repo — `cleanup-cache.yml` celuje w
   inny schemat (`buildcache-*`). Retencja obrazów bazowych zostaje otwartym pytaniem (sekcja 9,
   punkt 2) — usunięcie tagu, na którym zbudowano wydanie wciąż wdrożone, łamie rollback (sekcja 6.2).

### Faza 2 — strażnicy
`composer check-platform-reqs` w buildzie — **zrobione już w Fazie 1** (wymóg brief'u tego zadania,
patrz sekcja 6.1 i weryfikacja w sekcji "Faza 1" powyżej), nie odkładane. Zostaje z tej fazy:
reguła retencji obrazów bazowych (sekcja 9, punkt 2).

### Faza 3 — łatki bezpieczeństwa
Cykliczna przebudowa bazy (proponuję tygodniowo) z tagiem niosącym datę.

### Faza 4 — opcjonalna
Automatyczne otwieranie PR-a podbijającego zapięcie bazy po jej przebudowie. Przy jednoosobowym zespole może być zbędne — zwykłe przypomnienie wystarczy.

---

## 8. Czego świadomie nie ruszamy

**`deploy.sh` i sposób wdrażania.** Serwer dalej robi `docker compose pull` konkretnego tagu, `REGISTRO_VERSION` dalej ląduje w `.env`, rollback dalej jest jednym `docker pull`. Podział jest w całości po stronie budowania i **dla serwera niewidoczny**.

Sprawdzone: `scripts/server/deploy.sh` nie ma żadnej ścieżki synchronizującej pliki aplikacji do działającego kontenera. Rezygnacja z obrazu na rzecz wgrywania kodu odebrałaby nam odtwarzalność i rollback po tagu — a to jedyna część tego potoku, która działa dokładnie tak, jak zaprojektowano.

**`type=gha`** zostaje albo znika — bez znaczenia, bo nic nie robi. Do decyzji przy okazji.

**Zestaw pakietów `apt`/`pecl`.** `Dockerfile.base` przenosi obecny zestaw **jeden do jednego** —
`libc6-dev`, `libpng-dev`, `libsqlite3-dev`, `libxml2-dev`, `zlib1g-dev`, `linux-libc-dev` (część
jako zależności `-dev` innych pakietów, część transitywnie) i cały `perl` jadą dalej, mimo że żaden
z nich nie jest potrzebny w runtime — to potwierdzone skanowaniem Trivy z 2026-08-16
(`ci-cd-troubleshooting.md`, wpis "composer audit + skan obrazu"): **2275 znalezisk w warstwie OS**,
z czego **19 CRITICAL, wszystkie bez dostępnego fixa upstream**, i wszystkie sprowadzają się do
JEDNEGO źródła — `perl`/`libperl5.40`/`perl-base`/`perl-modules-5.40` (4 CVE) i `libxml2`/
`libxml2-dev` + `linux-libc-dev` (2 CVE) — pakietów, których runtime aplikacji nie wykonuje ani
razu. Wielostopniowe budowanie (kompilacja w jednym etapie, kopiowanie tylko skompilowanych `.so`
do czystszego etapu runtime, bez `-dev`/`perl`/narzędzi budowlanych) zmniejszyłoby tę powierzchnię
realnie — ale to jest **osobna, warta zrobienia zmiana, świadomie NIE ta**: dwie zmiany na raz
(podział na base/app I redukcja pakietów) uniemożliwiłyby przypisanie ewentualnej regresji jednej
przyczynie. Kandydat na kolejną fazę tego planu lub osobny plan, z własnym pomiarem "przed/po" na
Trivy.

---

## 9. Do rozstrzygnięcia przed startem

1. Czy przebudowa bazy ma być cykliczna (i jak często), czy wyłącznie ręczna po zmianie `Dockerfile.base`. **Od tego zależy, czy łatki bezpieczeństwa w ogóle będą do nas trafiać.**
2. Retencja obrazów bazowych — jak długo trzymamy stare, żeby rollback działał.
3. Czy `registro-base` ma być publiczny, czy prywatny (dziś `registro` jest prywatny).

---

**Pomiary z 2026-08-16.** Odniesienia do linii były prawdziwe tego dnia; przy zmianach w `Dockerfile` i `entrypoint.sh` traktuj je jako punkt wejścia, nie pewnik.
