---
paths:
  - "docker-compose*.yml"
  - ".github/workflows/**"
  - "config/horizon.php"
  - "scripts/**"
---

# CI/CD Troubleshooting

Kroniki incydentów. Zasady zawsze-obowiązujące: `deployment.md`.

## Incydent 2026-02-15: Docker API version incompatibility

### Problem
```
docker: Error response from daemon: client version 1.40 is too old.
Minimum supported API version is 1.44, please upgrade your client to a newer version.
```

### Przyczyna
- GitHub Actions zaktualizowało Docker Engine na runnerach do v29.1 (9 lutego 2026)
- Docker 29 wymaga minimum API v1.44
- `getong/mariadb-action@v1.1` używało Docker client API v1.40
- Action jest porzucone (ostatni release: czerwiec 2024)

### Rozwiazanie
Zamieniono `getong/mariadb-action` na natywny `services:` block we WSZYSTKICH workflow files:

```yaml
services:
  mariadb:
    image: mariadb:10.11
    ports:
      - 3306:3306
    env:
      MYSQL_ROOT_PASSWORD: secret
      MYSQL_DATABASE: registro_test
    options: >-
      --health-cmd="mysqladmin ping -h127.0.0.1 -psecret --silent"
      --health-interval=5s
      --health-timeout=3s
      --health-retries=10
```

### Zapobieganie
- **NIGDY** nie uzywaj `getong/mariadb-action` - jest porzucone i niekompatybilne
- Zawsze uzywaj natywnych `services:` block dla baz danych w GitHub Actions
- Monitoruj github.com/actions/runner-images/issues dla breaking changes

Naprawione we wszystkich workflow files. Źródła: `actions/runner-images` issue 13474, blog Dockera o wersji 29.

---

## Incydent 2026-06-29: Dual Queue Worker — jobs niewidoczne w Horizonie

### Problem
`docker-compose.yml` i `docker-compose.dev.yml` miały jednocześnie service `queue` (`queue:work`) ORAZ service `horizon`. Oba przetwarzały te same kolejki (emails, reminders, analytics, default). Jobs złapane przez surowy `queue:work` były niewidoczne w dashboardzie Horizona — failed list, autoscaling i metryki były dla nich martwe. 3-tygodniowy backlog przeszedł niezauważony.

### Przyczyna
Horizon v5 wymaga bycia JEDYNYM konsumentem kolejek. Równoległy `queue:work` przechwytuje jobs poza instrumentacją Horizona.

### Rozwiazanie
- Usunięto service `queue` z obydwu plików compose (`registro-queue` container)
- `config/horizon.php` supervisor-1 już pokrywał wszystkie 4 kolejki — nie wymagał zmian
- Dodano `horizon:snapshot` do schedulera (`routes/console.php`) — wymagany przez Horizon dla metryk
- Rozszerzono `config/horizon.php waits` na wszystkie kolejki (analytics:120, emails:120, reminders:180)
- Podniesiono `memory_limit` mastera Horizona z 64 → 128 MB
- `HorizonServiceProvider::gate()` — zmieniono z "każdy zalogowany w non-prod" na `hasRole('super-admin')` we wszystkich środowiskach

### Zapobieganie
- **NIGDY** nie dodawaj service `queue` (queue:work) gdy działa Horizon — Horizon zarządza workerami sam
- `horizon:snapshot` MUSI być w schedulerze — bez tego wykresy metryk są puste
- `config/horizon.php waits` — musi obejmować WSZYSTKIE kolejki zdefiniowane w supervisors
- Dashboard Horizona wymaga roli `super-admin` — nie "każdy zalogowany"

---

## Incydent 2026-07-07: Dual Queue Worker Recurrence — orphaned container survived fix z 2026-06-29

Kontynuacja incydentu powyżej ("Incydent 2026-06-29: Dual Queue Worker"). Ten sam symptom (dwa równoległe konsumenty kolejek Redis) wrócił mimo że fix był już zmergowany do develop.

### Problem
Kontener `registro-queue` (image `app-queue`, utworzony 2026-06-16, RestartCount 129) działał nieprzerwanie przez ~3 tygodnie, przetwarzając `php artisan queue:work redis --queue=emails,reminders,analytics,default` — te same kolejki co Horizon. Potwierdzono w logach na żywo (2026-07-07 ~21:15): oba kontenery (`registro-queue` i `registro-horizon`) przetwarzały te same klasy jobów (`MarkCartsAbandonedJob`, `RecalculateDailyStatisticsJob`) w tych samych oknach czasowych.

### Przyczyna
Fix z 2026-06-29 usunął service `queue` z `docker-compose.yml`/`docker-compose.dev.yml`, ale nikt nie wykonał `docker stop`/`docker rm` na już działającym kontenerze `registro-queue`. Docker restart policy kontenera utrzymywał go przy życiu niezależnie od zawartości pliku compose — edycja YAML nie ma żadnego wpływu na kontenery, które już działają i nie zostały zrekoncyliowane przez `docker compose up`/`down`.

### Rozwiazanie
```bash
docker stop registro-queue && docker rm registro-queue
```
Zweryfikowano:
- `docker compose config --services` nie zawiera `queue` (mysql, app, nginx, node, redis, scheduler, horizon, mailpit)
- `grep -rn "queue:work" docker-compose*.yml` — brak wyników we wszystkich wariantach (`.yml`, `.dev.yml`, `.prod.yml`, `.staging.yml`)
- `php artisan horizon:status` → "Horizon is running" po usunięciu kontenera
- Logi `registro-horizon` po usunięciu pokazują pojedyncze (nie zdublowane) przebiegi jobów

### Zapobieganie
- **Edycja `docker-compose.yml` NIE WYSTARCZY** żeby naprawić już działający system. Kontener z własnym restart policy przetrwa niezależnie od pliku compose w nieskończoność, dopóki nie zostanie jawnie zatrzymany.
- Po usunięciu service z pliku compose ZAWSZE wykonaj jedno z:
  - `docker stop <container> && docker rm <container>` (targeted removal), lub
  - `docker compose down && docker compose up -d` (pełna rekoncyliacja — usuwa wszystkie orphany na raz; Docker Compose też ostrzega o `orphan containers` przy `docker compose up` jeśli kontener nie odpowiada żadnemu service)
- Po każdej zmianie w `docker-compose*.yml` usuwającej service: sprawdź `docker ps -a` i porównaj z `docker compose config --services` — cokolwiek działa, a nie jest na liście, to orphan do usunięcia
- Zob. też: Incydent 2026-06-29 (ten sam plik, powyżej) — root cause dual-consumer był ten sam, ale tam problem był w pliku compose; tutaj problem był w LIVE środowisku które nigdy nie zostało zrekoncyliowane z poprawionym plikiem

---

## Incydent (znaleziony w review, nie shipped): `docker compose run` w forced-command recovery path

`scripts/server/deploy.sh`'s `force_clear_flag()` (task 4, stack-per-tenant epic) przeszedł przez
DWIE wersje. Pierwsza szukała wolumenu `storage-framework` ręcznie (`docker volume ls --filter
label=com.docker.compose.project=...`) i odmawiała działania przy 0 lub 2+ trafieniach — słusznie
(nie zgadywać przy czymś destrukcyjnym), ale zostawiało to zero ścieżki odzyskania przez forced
command, gdy dopasowanie zawiodło.

Druga wersja (poprawka na powyższe) zamieniła to na `docker compose -f "$COMPOSE_FILE" run --rm
--no-deps --entrypoint rm app -f <plik>` — i to była REGRESJA złapana w code review, nie shipped.
**KAŻDA subkomenda Compose (`run`, `config`, `ps`) interpoluje CAŁY plik przed wyborem serwisu** —
`docker-compose.prod.yml` ma `${APP_KEY:?}`/`${APP_DOMAIN:?}`/`${REDIS_PASSWORD:?}`, więc
zblankowany/zepsuty `.env` wywala `docker compose run` na etapie interpolacji, ZANIM `--entrypoint
rm` się wykona — dokładnie ten scenariusz ("hasło zblankowane złą edycją"), dla którego funkcja
istnieje, i dokładnie ten sam root cause, który już wywala primary path
(`docker compose exec -T app php artisan up`). Zweryfikowane: `docker compose run` z pustym
`REDIS_PASSWORD` → `error while interpolating x-app-env.REDIS_PASSWORD: required variable ... is
missing a value` — kod wyjścia 1, PRZED jakąkolwiek próbą `rm`.

**Finalny fix: nie pytaj Compose o nic.** Nazwa wolumenu jest OBLICZANA (nie wyszukiwana): Compose
nazywa wolumeny deterministycznie jako `${project}_${volume-key}`, a `project` to
`${TENANT_PREFIX:-registro}` — czytane samym `grep` z `.env` (ta sama technika co `status` action),
nie wymaga interpolacji CAŁEGO pliku. `docker volume inspect` (surowy docker, nie compose)
potwierdza istnienie PRZED dotknięciem — `docker run -v nazwa:/path` na nieistniejącej nazwie CICHO
TWORZY pusty wolumen, co dałoby fałszywy sukces bez realnego usunięcia flagi. Zweryfikowane
end-to-end pod zepsutym `.env` (puste `REDIS_PASSWORD`): flaga usunięta poprawnie.

**Zasada:** w forced-command recovery path (SSH restricted command, brak dostępu do surowego docker
poza zdefiniowaną gramatyką) — NIGDY `docker compose <cokolwiek>` jeśli plik ma choć jeden
`${VAR:?}`. Kompozycja subkomend Compose nie ma trybu "zignoruj resztę pliku, obchodzi mnie tylko
ten serwis" — interpolacja jest zawsze całościowa. Pełny opis + obie weryfikacje (bug i fix, oba pod
tym samym zepsutym `.env`): `app/docs/deployment/tenant-compose-stack.md`.

---

## Incydent (znaleziony przez faktyczne uruchomienie, nie shipped): `apply.sh` — 6 bugów w jednej sesji walidacji

Task 6 (`scripts/server/apply.sh`, `tenant-check.sh`, `tenant-backup.sh` — reconciler dla
stack-per-tenant). Każdy z poniższych złapany dopiero przez REALNE uruchomienie danej ścieżki
(`docker build` z bieżącego brancha, prawdziwy `git clone`, prawdziwy `systemd-run --user`), nie przez
inspekcję kodu. Pełny opis: `app/docs/deployment/tenant-apply.md`.

1. **`log()`/`die()` czytały `$LOG_FILE` zanim zmienna istniała.** `LOG_FILE` zależy od `SLUG`,
   znanego dopiero po parsowaniu argumentów — ale `die()` jest wywoływane już PRZY walidacji argumentów.
   Pod `set -u`: `unbound variable` zamiast realnego komunikatu błędu. Fix: `${LOG_FILE:-/dev/null}`.
2. **Plik locka leżał W ŚRODKU katalogu, do którego `git clone` miał dopiero sklonować repo.**
   `mkdir -p "$STACK_DIR"; exec 9>"${STACK_DIR}/.apply.lock"` — katalog przestawał być pusty PRZED
   `git clone`, a `git clone` odmawia klonowania do niepustego katalogu. Każdy pierwszy `apply` dla
   nowego tenanta padał. Fix: całe bookkeeping skryptu (lock/log/status/pre-dumpy) w osobnym
   `STACKS_ROOT/.state/<slug>/`, nigdy w git working tree.
3. **`registro:tenant-provisioned --assert` nie znaczy "spójny".** Nawet gdy `assertConsistent()`
   przechodzi, komenda i tak zwraca `FAILURE` (`not-provisioned`) dla świeżego, jeszcze
   nie-sprowizjonowanego stacka. Traktowanie każdego niezerowego exit code jako "niespójność, die"
   wywalałoby KAŻDY pierwszy `apply` dla KAŻDEGO nowego tenanta, zawsze. Rozróżnienie po TREŚCI
   wyjścia (dokładnie `not-provisioned` = OK, cokolwiek innego = realna niespójność), nie po exit code.
4. **`VAR="$(cmd)"` w osobnej linii NIE jest warunkiem pod `set -e`.** Linia `RC=$?` zaraz po takim
   przypisaniu nigdy się nie wykonywała — `set -e` zabijał skrypt w momencie gdy `cmd` (oczekiwanie
   zwracające czasem niezero, jak w punkcie 3) zwracało błąd. Fix: `VAR="$(cmd)" || RC=$?`.
5. **`find`, w przeciwieństwie do bash-owego globa, nie pomija plików/katalogów zaczynających się od
   kropki.** Skan sierot w `tenant-check.sh` raportował własny katalog `.state/` apply.sh jako
   "osierocony katalog tenanta". Fix: `-not -name '.*'`.

**Zasada:** żaden z tych pięciu nie był widoczny przy samej lekturze kodu — wszystkie wymagały
faktycznego uruchomienia ścieżki, którą łamały (pierwszy `apply` dla nowego tenanta, `--assert` na
świeżym stacku, skan `check` na drzewie z `.state/`). Przy skryptach powłoki obsługujących "pierwsze
uruchomienie czegoś nowego" — zawsze faktycznie wykonaj tę ścieżkę, nie tylko `bash -n`/shellcheck.

---

## Incydent (drugi przegląd infrastrukturalny, znaleziony i naprawiony): `apply.sh` — plik statusu mógł kłamać po sygnale

Kontynuacja incydentu powyżej. Pełny opis + reprodukcja SIGTERM krok po kroku:
`app/docs/deployment/tenant-apply.md` → "Infrastructure review — six more fixes".

1. **KRYTYCZNE — `on_exit`'s `$?` czyta 0 nawet gdy proces ginie od nieprzechwyconego sygnału**
   (bash raportuje status ostatniej komendy, nie sygnału, gdy sygnał trafia między komendami).
   Stary `OK` z poprzedniego udanego przebiegu przeżywał SIGTERM w trakcie kolejnego. Fix: `RUNNING`
   pisane BEZWARUNKOWO jako pierwsza rzecz po zajęciu locka — jedyny sposób, żeby plik znów pokazał
   `OK`, to żeby ten KONKRETNY przebieg faktycznie dobiegł końca. Traps (HUP/INT/TERM, wzorzec z
   `deploy.sh`) to tylko szybsza, bardziej informacyjna ścieżka (natychmiastowy `FAILED` z powodem
   zamiast czekania na próg wieku `RUNNING` w `check.sh`) — NIE gwarancja; SIGKILL i tak ich nie złapie.
   **Odkryte przy okazji:** bash ODKŁADA wykonanie trap handlera do zakończenia BIEŻĄCEJ komendy
   pierwszoplanowej (udokumentowane zachowanie bash) — SIGTERM wysłany w trakcie długiego `docker
   compose` nie przerywa go, handler odpala dopiero gdy ta komenda się skończy. Dotyczy też własnego
   `on_signal` w `deploy.sh`.
2. **WYSOKIE — maintenance mode zwalniał się przy dokładnie tych dwóch awariach, które oznaczają
   "ruch może trafić do złego tenanta"**: niespójność `registro:tenant-provisioned --assert` i
   niezgodność sondy X-Tenant. Naprawione (`KEEP_MAINTENANCE=true` w obu miejscach), zweryfikowane
   przez realne wywołanie tej samej `clear_maintenance()` na żywym stacku ze SPREPAROWANYM złym
   X-Tenant: `KEEP_MAINTENANCE=true` → strona zostaje na 503; `KEEP_MAINTENANCE=false` (stare
   zachowanie) → strona wraca na 200, serwując POTWIERDZONY zły slug.
3. **WYSOKIE — "ponowne uruchomienie to czysty retry" nieprawdziwe dla migracji** (DDL MySQL nie jest
   atomowe — kolumna A może zostać, migracja nierozliczona, retry pada na "column already exists").
   Doprecyzowano w dokumentacji. Osobno: `REGISTRO_VERSION` eksportowane jako zmienna powłoki DZIAŁA
   na nowym tagu już od `pull`/`migrate`/`up -d` — zapis do `.env` na końcu chroni REKORD, nie STAN
   DZIAŁAJĄCY; operator ufający samemu `.env` między `up -d` a końcem może zobaczyć starą wersję,
   podczas gdy działa już nowa.
4. **WYSOKIE — konflikt blokady restic bez udokumentowanego `restic unlock`.** Zweryfikowane
   bezpośrednio: `restic backup` bierze blokadę WSPÓLNĄ (nie blokuje kolejnych backupów), martwa
   blokada realnie blokuje dopiero przy konflikcie z operacją WYŁĄCZNĄ (`prune`/`check`) — węziej niż
   pierwotnie zakładano, ale realne. Dodano wykrywanie `already locked` w wyjściu restic + gotowa
   komenda `restic unlock` z wypełnionymi zmiennymi w komunikacie błędu.
5. **ŚREDNIE — nieudany backup po ZDROWYM, żywym release'ie raportował FAILED**, tak samo jak
   faktycznie zepsuty deploy — operator nie mógł odróżnić "strona padła" od "strona żyje, tylko backup
   nawalił". Nowy status `DEGRADED` (exit 5), `REGISTRO_VERSION` i tak przypinane (release faktycznie
   żyje). Zweryfikowane end-to-end dwa razy (awaria → `DEGRADED`, potem naprawa hasła restic → `OK`)
   na żywym, health-checked stacku.
6. **ŚREDNIE — alokacja portu: dwaj pierwsi tenanci provisionowani jednocześnie mogli wybrać ten sam
   port**, a przegrany trwale utykał (wybór zapisywany w `.env`, każdy kolejny apply odczytywał
   kolizję zamiast realokować) — w przeciwieństwie do alokacji subnetu, gdzie nic nie jest
   zapisywane wcześniej, więc retry naturalnie skanuje od nowa. Naprawione globalnym, krótkim
   `flock` wokół sekcji skan-potem-rezerwacja. Współbieżne provisionowanie RÓŻNYCH tenantów jest
   celowo wspierane (osobny lock niż per-tenantowy `STATE_DIR/apply.lock`), nie odrzucane.

**Zasada:** dokładnie jak wyżej — żaden z tych sześciu nie był widoczny przy samej lekturze kodu.
Reprodukcja SIGTERM wymagała wstrzykniętego `sleep` w kopii testowej (realne okno wyścigu przez
round-trip narzędzi było za wąskie) — deterministyczne opóźnienie w rzucanej kopii, zdiffowane
przeciwko oryginałowi, żeby dowieść że różni się dokładnie o jedną linię.
