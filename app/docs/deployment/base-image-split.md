# Podział obrazu: środowisko osobno, aplikacja osobno — plan

**Status:** plan, nic z tego nie jest zaimplementowane.
**Data:** 2026-08-16.
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

W CI job `build` trwa **3 min 29 s – 3 min 38 s** (zmierzone na `v0.13.0-rc15` i `rc16`).

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

### Faza 1 — podział i pomiar
Wydzielenie `Dockerfile.base`, zbudowanie bazy raz, zapięcie tagu w `Dockerfile` aplikacji, przebudowa workflow o osobny krok. **Warunek zaliczenia: zmierzony czas buildu aplikacji poniżej minuty, obraz działa na UAT.**

### Faza 2 — strażnicy
`composer check-platform-reqs` w buildzie. Reguła retencji obrazów bazowych. Wpis do `ci-cd-troubleshooting.md`.

### Faza 3 — łatki bezpieczeństwa
Cykliczna przebudowa bazy (proponuję tygodniowo) z tagiem niosącym datę.

### Faza 4 — opcjonalna
Automatyczne otwieranie PR-a podbijającego zapięcie bazy po jej przebudowie. Przy jednoosobowym zespole może być zbędne — zwykłe przypomnienie wystarczy.

---

## 8. Czego świadomie nie ruszamy

**`deploy.sh` i sposób wdrażania.** Serwer dalej robi `docker compose pull` konkretnego tagu, `REGISTRO_VERSION` dalej ląduje w `.env`, rollback dalej jest jednym `docker pull`. Podział jest w całości po stronie budowania i **dla serwera niewidoczny**.

Sprawdzone: `scripts/server/deploy.sh` nie ma żadnej ścieżki synchronizującej pliki aplikacji do działającego kontenera. Rezygnacja z obrazu na rzecz wgrywania kodu odebrałaby nam odtwarzalność i rollback po tagu — a to jedyna część tego potoku, która działa dokładnie tak, jak zaprojektowano.

**`type=gha`** zostaje albo znika — bez znaczenia, bo nic nie robi. Do decyzji przy okazji.

---

## 9. Do rozstrzygnięcia przed startem

1. Czy przebudowa bazy ma być cykliczna (i jak często), czy wyłącznie ręczna po zmianie `Dockerfile.base`. **Od tego zależy, czy łatki bezpieczeństwa w ogóle będą do nas trafiać.**
2. Retencja obrazów bazowych — jak długo trzymamy stare, żeby rollback działał.
3. Czy `registro-base` ma być publiczny, czy prywatny (dziś `registro` jest prywatny).

---

**Pomiary z 2026-08-16.** Odniesienia do linii były prawdziwe tego dnia; przy zmianach w `Dockerfile` i `entrypoint.sh` traktuj je jako punkt wejścia, nie pewnik.
