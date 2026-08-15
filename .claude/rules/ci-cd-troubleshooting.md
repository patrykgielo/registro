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

---

## Incydent (znaleziony przez analizę, przed shippingiem): `sync-certificate.sh` enumerował TYLKO
## starą bazę — dedykowane stacki znikały z certyfikatu na każdym przebiegu crona

Kontynuacja epiki stack-per-tenant (task 6+). `sync-certificate.sh` budował listę nazw SAN
wyłącznie z `php artisan tenants:hostnames` w kontenerze STAREGO, współdzielonego stacka. Tenant
provisionowany na własnym stacku (`/opt/stacks/<slug>/`, własna baza) nigdy tam nie trafiał — a
skrypt wystawia certyfikat na DOKŁADNIE tę listę (`certbot --expand`), więc każda nazwa dodana
ręcznie znikała przy najbliższym przebiegu (co 15 min). Pełny opis: `edge-stack.md` → "Known gap,
fixed", `instalacja-tenanta-od-zera.md` krok 2.5.

**Znaleziony, ale NIE naprawiony pułapką, przy projektowaniu naprawy:** oczywisty pierwszy pomysł —
odpalić `tenants:hostnames` też wewnątrz kontenera każdego dedykowanego stacka — jest SAM W SOBIE
błędny. Ta komenda liczy `baseDomain + "<org-slug>.<baseDomain>"`; na dedykowanym stacku
`APP_DOMAIN` to już `PRIMARY_HOST` tenanta (np. `acme.registrolabs.com`), a jedyna organizacja w
jego bazie ma TEN SAM slug (`acme`) — wynik: `acme.acme.registrolabs.com`, nazwa której nic nie
serwuje i której nie pokrywa jednopoziomowy wildcard. Let's Encrypt odrzuca CAŁE zamówienie przy
jednej niewalidującej się nazwie — czyli naiwna naprawa zepsułaby odnawianie certyfikatu dla
WSZYSTKICH tenantów naraz, w tym starego stacka. Znalezione przez analizę przepływu danych
(`apply.sh`'s `.env` generation → `config('app.domain')` → `ListTenantHostnamesCommand`), nie przez
uruchomienie — ale zweryfikowane logicznie krok po kroku przed napisaniem jakiegokolwiek kodu.

**Naprawa:** źródłem nazw dla dedykowanego stacka jest `TENANT_HOSTS`, odczytywane NA ŻYWO z
środowiska kontenera `app` (`docker compose exec app sh -c 'echo $TENANT_HOSTS'`) — to już jest
dokładna, ustalona przy `apply` allowlista, którą `ResolveTenant`/`TrustedTenantHosts` i tak
egzekwują. Zero przeliczania.

**Fail-safe, nie fail-shrink:** katalog bez pliku compose (śmieć albo `.state/` — `find` NIE pomija
kropkowanych wpisów, patrz incydent apply.sh wyżej) = cichy skip. Katalog z plikiem compose, który
NIE odpowiada (kontener padnięty, zepsuty `.env`, timeout) = **przerwanie CAŁEGO przebiegu przed
dotknięciem certbota** — nigdy cichego zawężenia listy. Zweryfikowane end-to-end w piaskownicy
(fake `su`/`docker`/`certbot`/`id` w PATH, cztery scenariusze: brak `/opt/stacks`, `.state` +
katalog-śmieć, zdrowy stack, niedostępny stack) — `certbot certonly` w scenariuszu niedostępnego
stacka NIE został wywołany ani razu.

**Zasada:** przy REUŻYWANIU istniejącej komendy/mechanizmu w NOWYM kontekście (inna architektura,
inny model danych) — prześledź co faktycznie policzy, zanim się do niej podłączysz. "Ta sama
komenda" nie znaczy "ten sam poprawny wynik", jeśli założenia kontekstu się zmieniły.

---

## Incydent (znaleziony przez analizę planu dwóch maszyn, przed shippingiem): `apply.sh` domyślnie
## doklejał OBIE domeny każdemu tenantowi — wywaliłoby certyfikat na całej maszynie

Faza 1 planu `dwie-maszyny-uat-preprod.md`. `apply.sh` miał `EDGE_DOMAINS=(registrolabs.com
registroapps.com)` na sztywno i domyślny `[hosts]` = `<slug>.registrolabs.com,<slug>.registroapps.com`
— **każdy** tenant dostawał obie domeny, także w `server_name` szablonu brzegu
(`tenants.d/_example.conf.disabled`, ta sama para wpisana na sztywno, „musi być zgodne z EDGE_DOMAINS
ręcznie" wprost w komentarzu). Nieszkodliwe przy jednej maszynie. W modelu docelowym
(`registrolabs.com` = UAT, `registroapps.com` = PreProd, osobne maszyny) w dniu, w którym
`*.registroapps.com` zacznie wskazywać na drugą maszynę, walidacja HTTP-01 tej nazwy dla tenanta
stojącego na UAT trafia w maszynę bez pliku wyzwania — Let's Encrypt odrzuca **całe** zamówienie przy
jednej niewalidującej się nazwie, więc odnowienie certyfikatu przestaje działać dla **wszystkich**
tenantów na UAT naraz, cicho, do wygaśnięcia.

**Naprawa:** domena to własność MASZYNY, nie wywołania. `apply.sh` już czyta `CERT_DIR` z `.env`
legacy checkoutu (`${LEGACY_APP_DIR}/.env`) — ten sam precedens zastosowany do domeny: gdy `[hosts]`
nie podano, czyta `APP_DOMAIN` z tego samego pliku (`deploy-init.sh` już o to pyta i już to zapisuje,
dla routingu poddomen legacy stacka — żadnego nowego klucza do skonfigurowania). Brak `APP_DOMAIN` =
odmowa natychmiast, przed jakimkolwiek dotknięciem dysku/Dockera, z komunikatem wprost nazywającym co
ustawić. Jawny argument `[hosts]` nadal nadpisuje w całości. Szablon brzegu: `server_name` zamieniony
z pary na sztywno na jeden placeholder (`TENANT_SERVER_NAMES`), podstawiany przez `apply.sh` z
`HOSTS` tego konkretnego stacka (przecinki → spacje) — koniec ręcznej synchronizacji stałej z
szablonem.

Zweryfikowane end-to-end w piaskownicy (throwaway git origin, realny lokalny `docker build`, realny
`docker compose` — bez dotykania serwera/dev-bazy/Let's Encrypt): domyślny tenant dostał dokładnie
jedną nazwę w `TENANT_HOSTS` i w wyrenderowanym `server_name`; jawny dwuelementowy `[hosts]` dał
dokładnie te dwie nazwy w obu miejscach; legacy `.env` bez `APP_DOMAIN` odmówił natychmiast z
poprawnym komunikatem; `tenant-check.sh` przeszedł cicho; wyrenderowany vhost przeszedł `nginx -t`
w kontenerze `nginx:1.25-alpine` z throwaway self-signed certyfikatem. Pełny opis:
`app/docs/deployment/tenant-apply.md` → „One tenant, one domain".

**Zasada:** wartość należąca do MASZYNY (domena, katalog certyfikatu) nie powinna być ani stałą
wpisaną na sztywno w skrypcie, ani argumentem powtarzanym przy każdym wywołaniu — czytaj ją z
`.env` instalacji, tym samym mechanizmem co już istniejące wartości tego typu (`CERT_DIR`), zamiast
wymyślać nowy.

---

## Incydent (Faza 2 planu dwóch maszyn, znaleziony w piaskownicy, przed shippingiem): cztery
## poprawki blokujące, jedna z nich odsłoniła świeżego buga we własnym fixie

Kontynuacja epiki stack-per-tenant. Cztery niezależne poprawki (dwie warunki konieczne dla istnienia
maszyny PreProd, dwie higieniczne), wszystkie zweryfikowane realnym uruchomieniem w piaskownicy
(fake `su`/`docker`/`certbot` na PATH dla `sync-certificate.sh`, realny Docker + realny restic przez
`restic/restic` obraz dla backupu wolumenów), nie samą lekturą kodu.

**1. `sync-certificate.sh` umierał bez stacka legacy.** Maszyna bez działającego stacka legacy
(świeży PreProd — checkout jest, kontenery nigdy nie wystartowały) to legalna konfiguracja wnosząca
zero nazw legacy, nie błąd. Rozróżnienie: `docker compose ps -q app` (bez `-a`, więc pokazuje TYLKO
żywe kontenery) mówi "czy w ogóle jest tu coś do zapytania" — puste = legalne zero nazw (kontynuuj),
niepuste ale sama `tenants:hostnames` pusta/błędna = "jest, ale zepsute" (przerwij, bez zmiany
certyfikatu). Dodano też twardy strażnik na końcu: jeśli WSZYSTKIE źródła (legacy + dedykowane
stacki + www) razem dały zero nazw — przerwij, nigdy nie proś certbota o certyfikat na zero nazw.

**2. `sync-certificate.sh` dla dedykowanych stacków czytał `TENANT_HOSTS` z żywego kontenera
(`docker compose exec`), nie z `.env`.** Poprawne dla "jeden zawsze-żywy stack per klient", błędne
odkąd UAT zaczął hostować projekty potencjalnych klientów, tworzone i zatrzymywane między sesjami —
"katalog jest, kontener stoi" stało się stanem NORMALNYM, a stary fail-safe (słusznie) traktował to
tak samo jak "zepsute", zamrażając odnowienie dla WSZYSTKICH innych tenantów przez jeden śpiący
stack. `apply.sh` i tak zapisuje `TENANT_HOSTS` do `.env` tego stacka — czytanie STAMTĄD to ta sama
wartość, dostępna niezależnie od tego, czy kontener akurat działa.

**Bug znaleziony PRZY OKAZJI naprawy #1, nie przewidziany z góry:** `DESIRED` mogło teraz legalnie
zaczynać się jako pusty string (brak legacy). Istniejąca linia scalająca
`DESIRED="$(printf '%s\n%s\n' "$DESIRED" "$STACK_NAMES" | sort -u)"` przy pustym `$DESIRED` zostawiała
JEDNĄ pustą linię w wyniku `sort -u` (bo pusta linia to też "unikalna wartość") — dawało to gołe
`-d ` (pusty argument domeny) przekazane do `certbot`. Znalezione przez FAKTYCZNE uruchomienie
scenariusza "brak legacy + jeden dedykowany stack" w piaskownicy, nie przez inspekcję. Fix:
`sed '/^$/d'` przed `sort -u` w OBU miejscach scalania (dedykowane stacki i `www`).

**3. `apply.sh` nigdy nie zapisywał `NGINX_RELOAD_CONTAINER`**, mimo że własny komentarz
`sync-certificate.sh` mówił, że "apply ma to zrobić przy cutoverze". Na maszynie z brzegiem
(`docker-compose.edge.yml`) reload po odnowieniu trafiał w zły kontener — certyfikat odnawiał się na
dysku, nginx dalej serwował stary, cicho, do wygaśnięcia. Naprawa NIE jest bezwarunkowym zapisem przy
każdym `edge-sync` (ten krok biegnie przy KAŻDYM apply, cutover czy nie — brzeg odpowiada czystym
HTTP, dopóki udokumentowany ręczny cutover się nie wydarzy) — zapis jest bramkowany sprawdzeniem
FAKTYCZNEGO bind mountu działającego kontenera edge-nginx (`docker inspect` na źródle mountu
`/etc/nginx/conf.d/default.conf`), nie zmienną `EDGE_NGINX_CONF`, bo udokumentowana procedura
cutover ustawia tę zmienną TYLKO dla jednej komendy, nigdy nie zapisuje jej do `.env`. Nazwa
kontenera czytana z `docker-compose.edge.yml`'s `container_name`, nie zgadywana drugi raz.

**4. `tenant-backup.sh`/`apply.sh` backupowały wyłącznie `mysqldump` — wolumeny
`storage-app-public`/`storage-app-private` nie były objęte niczym.** Naprawa: staging obu wolumenów
do katalogu na hoście (przez jednorazowy kontener `docker run -v <wolumen>:/src:ro`), potem JEDNO
wywołanie `restic backup` z plikiem `.sql` I oboma katalogami naraz — jeden snapshot, nie dwa
bliskie w czasie. Dwa realne bugi znalezione TYLKO przez faktyczne uruchomienie na prawdziwym
wolumenie Dockera:
- `docker run -v nieistniejący:/sciezka` CICHO TWORZY pusty wolumen zamiast błędu — strażnik
  `docker volume inspect` PRZED montowaniem, zweryfikowany że faktycznie odpala (wywołanie z nazwą,
  która nigdy nie istniała: funkcja zwróciła błąd I `docker volume ls` potwierdził brak nowego
  wolumenu).
- **GNU `cp -a /src/. /dest/` nie tylko kopiuje zawartość DO `/dest`, nadpisuje też WŁASNE
  uprawnienia/właściciela `/dest` metadanymi źródła** (root:root, bo katalog wolumenu sam jest
  root-owned). Kontener stagingowy musi być `--user 0:0` (root), bo pliki w wolumenie należą do
  stałego usera obrazu `laravel` (UID 1000, ADR-013), a katalog na hoście tego UID-a nie wpuszcza —
  ale bez `chown -R $(id -u):$(id -g) /dest` jako DRUGIEJ komendy w tym samym uprzywilejowanym
  `docker run`, katalog stagingowy cicho zmieniał właściciela na roota, a `rm -rf` zwykłego
  użytkownika (bez sudo na tej maszynie) kończyło się `Permission denied` od DRUGIEGO uruchomienia
  w górę. Zweryfikowane wprost oboma wariantami (bez `chown` — błąd sprzątania; z `chown` — sukces)
  zanim trafiło do kodu produkcyjnego.

Pełny opis wszystkich czterech + osiem wyników walidacji: `app/docs/deployment/tenant-apply.md`,
`app/docs/deployment/edge-stack.md`, `app/docs/deployment/instalacja-tenanta-od-zera.md` (Część
7.5/7.8/8.1/8.6/8.7).

**Zasada:** naprawa jednego założenia ("legacy zawsze istnieje") może cicho złamać kod NIŻEJ w tym
samym pliku, który zakładał to samo założenie w innej formie (scalanie list przez `sort -u` zakładało
niepusty start). Po KAŻDEJ zmianie założenia — przeszukaj resztę pliku pod kątem miejsc, które po
cichu na nim polegały, nie tylko napraw miejsce, które explicite о to poproszono. Osobno: przy
kopiowaniu z Docker volume na hosta z `cp -a` — GNU coreutils re-stempluje docelowy katalog
metadanymi źródła, nie tylko jego zawartość; jeśli docelowy katalog musi zostać własnością
nieuprzywilejowanego usera, `chown` z powrotem MUSI się odbyć w tym samym uprzywilejowanym
kontenerze, nie jako osobny, później uruchamiany krok.

---

## Incydent (drugi przegląd infrastrukturalny tej samej Fazy 2, dwa realne bugi potwierdzone
## reprodukcją, nie teorią): `apply.sh` cichcem cofał cutover TLS; `sync-certificate.sh` mylił
## "nic tu nie ma" z "nie potrafię sprawdzić"

Kontynuacja bezpośrednio powyżej. Niezależny przegląd odtworzył oba bugi na jednorazowych
projektach compose/wolumenach, nie tylko przeczytał kod.

**1. KRYTYCZNE — `apply.sh`'s krok `edge-sync` cofał zakończony cutover TLS przy KAŻDYM
kolejnym apply.** `docker-compose.edge.yml` montuje config nginxa przez
`${EDGE_NGINX_CONF:-edge.conf}`. Udokumentowany ręczny cutover (`edge-stack.md`) ustawia tę
zmienną TYLKO dla jednej komendy — nigdy nie zapisuje jej do `.env`. `edge-sync` uruchamia `docker
compose ... up -d edge-nginx` przy KAŻDYM apply, dla KAŻDEGO tenanta, w procesie, gdzie ta zmienna
jest pusta. Zreprodukowane na jednorazowym projekcie compose: cutover → `up -d` bez zmiennej →
`docker inspect` pokazał zarówno źródło mountu, JAK i `StartedAt` zmienione — kontener
zrekreowany z powrotem na bootstrap `edge.conf`, cicho, bez `die()`, tylko linia loga czytana jako
informacyjna. Pierwszy apply dla dowolnego tenanta PO cutoverze zdejmowałby terminację TLS dla
WSZYSTKICH tenantów za brzegiem naraz.

**Naprawa:** PRZED `up -d edge-nginx`, odczytaj FAKTYCZNY bind mount działającego kontenera
(`docker inspect` na `/etc/nginx/conf.d/default.conf`), wyciągnij z niego nazwę pliku i wyeksportuj
`EDGE_NGINX_CONF` na tę samą wartość dla TEGO wywołania — `up -d` rekreuje kontener z tym samym
configiem, który już miał. Kontener, który jeszcze nie istnieje (pierwsze uruchomienie brzegu) nie
ma nic do zachowania — `EDGE_NGINX_CONF` zostaje puste, co jest poprawnym domyślnym stanem
bootstrap. Zweryfikowane na jednorazowym projekcie compose (przepisane hosty absolutnych ścieżek
`/etc/letsencrypt` na katalogi w piaskownicy, żeby nie dotykać realnych ścieżek roota): cutover →
`apply` uruchomiony DWA RAZY z rzędu z `EDGE_NGINX_CONF` niepustym w środowisku procesu → mount
źródło NIEZMIENIONE (`edge-tls.local.conf`) przy obu przebiegach, `NGINX_RELOAD_CONTAINER` zapisane
raz, bez duplikatu przy trzecim przebiegu. Kontrola negatywna: ten sam `up -d` BEZ poprawki (gołe
`docker compose up -d edge-nginx`, dokładnie stare zachowanie) na tym samym stanie faktycznie cofnął
mount na `edge.conf` — dowód, że bug był realny, nie hipotetyczny.

**2. WYSOKIE — `sync-certificate.sh`'s sonda "czy legacy stack działa" nie odróżniała "nic tu nie
ma" od "nie potrafię sprawdzić".** Pierwsza wersja poprawki (patrz incydent wyżej) używała `docker
compose ps -q app 2>/dev/null || true` — ale `docker compose ps`, jak KAŻDA subkomenda Compose,
interpoluje CAŁY plik `.env` przed odpowiedzią (ten sam fakt co incydent "docker compose run w
forced-command recovery path" wyżej). Zblankowany/zepsuty `.env` legacy stacka (dokładnie incydent
REDIS_PASSWORD już w tej kronice) sprawiał, że `docker compose ps -q app` **zawodził** (exit
niezero, puste stdout) MIMO że kontener wciąż działał i serwował ruch na środowisku, z jakim
wystartował. Odczytane jako "legalnie nic tu nie ma" → zero nazw legacy wniesionych → `certbot
--expand` wystawiał certyfikat OKROJONY z każdej nazwy legacy, podczas gdy legacy stack wciąż stał i
serwował na starym certyfikacie. Dokładnie ten sam bug klasy "cichego skurczenia", który cały ten
plik ma zapobiegać, wpuszczony tylnymi drzwiami przez WŁASNĄ wcześniejszą poprawkę.

**Naprawa:** sonda przez `docker inspect --format '{{.State.Running}}' registro-app` — surowe
zapytanie o obiekt kontenera PO NAZWIE, nigdy nie dotyka interpolacji Compose. Nazwa kontenera
STAŁA (`docker-compose.prod.yml`'s `container_name: ${TENANT_PREFIX:-registro}-app`, a
`TENANT_PREFIX` zostaje puste na legacy stacku z założenia) — ten sam precedens co domyślne
`registro-nginx` już w tym pliku. Trzy stany rozróżnione: `docker inspect` zwraca `true` → działa,
odpytaj `tenants:hostnames` (istniejąca ścieżka bez zmian); zwraca `false` → istnieje, ale
zatrzymany, zero nazw; zawodzi z komunikatem "no such object" → kontenera nigdy nie było, zero
nazw; zawodzi z INNYM komunikatem (`daemon nieosiągalny`, brak uprawnień) → **fatalne**, `die()`,
nigdy nie czytane jako "nic tu nie ma".

**Bug znaleziony PRZY OKAZJI implementacji tej naprawy, nie przewidziany:** `VAR="$(cmd)"` w
osobnej linii, bez `|| ...`, pod `set -e` — DOKŁADNIE incydent "6 bugów w `apply.sh`", punkt 4, w
NOWYM miejscu. `docker inspect` na nieistniejącym kontenerze (dokładnie ten scenariusz "absent
checkout", który ta gałąź ma poprawnie obsłużyć) zabijał skrypt W TEJ LINII, zanim `LEGACY_INSPECT_RC=$?`
w ogóle się wykonało — zreprodukowane przez faktyczne uruchomienie (`bash -x`), nie przez inspekcję.
Fix: `LEGACY_INSPECT_RC=0; VAR="$(cmd)" || LEGACY_INSPECT_RC=$?`. Zweryfikowane po fixie: cztery
stany (absent, running zdrowy, running z zepsutym `.env`, `docker inspect` zawodzący z realnego
powodu) wszystkie dają poprawny wynik, wszystkie sześć wcześniejszych scenariuszy piaskownicy nadal
przechodzi.

Pełny opis: `app/docs/deployment/edge-stack.md`, `app/docs/deployment/tenant-apply.md`. Trzeci,
średni finding tego samego przeglądu (procedura przywracania plików nie dziedziczyła poprawki
własności z backupu — `chown -R $(id -u):$(id -g)` po stronie backupu nie ma odpowiednika po
stronie przywracania, więc przywrócone pliki mogą zostać niezapisywalne dla realnego UID aplikacji)
opisany w `instalacja-tenanta-od-zera.md` Część 8.6/8.7 — zweryfikowany z UMYŚLNIE innym
symulowanym UID `deploy` (1002, realny przykład z ADR-010) żeby zbieżność UID hosta z UID aplikacji
w piaskownicy nie zamaskowała buga po raz drugi.

**Zasada:** ten sam bug ("`cmd` w compose zawodzi z powodu `.env`, nie z powodu stanu, o który
pytasz") potrafi wrócić w ZUPEŁNIE innym miejscu tego samego repo, jeśli naprawa w jednym miejscu
(dedykowane stacki, incydent wyżej) nie zostanie systematycznie zastosowana do KAŻDEGO miejsca z tym
samym kształtem zapytania (legacy stack, ten incydent). Przy dowolnym `docker compose <cokolwiek>`
używanym WYŁĄCZNIE jako "czy to w ogóle działa" — pytaj `docker inspect` po nazwie kontenera
zamiast, nie warunkuj bramki na subkomendzie Compose, która i tak zawsze interpoluje cały plik.
Osobno: gdy walidujesz mechanizm ownership/permission w piaskownicy, sprawdź NAJPIERW czy UID hosta
testowego przypadkiem nie zgadza się z UID, którego bug dotyczy — zbieżność cicho unieważnia test
(złapane i naprawione dwa razy w tej samej sesji, patrz też incydent bezpośrednio powyżej).

---

## Incydent (Faza 3 planu dwóch maszyn, znaleziony przez realną migrację, nie inspekcję):
## `stage_volume()` cicho tworzyło PUSTY backup obu wolumenów storage na prawdziwym obrazie

`apply.sh`'s własny finalny krok backupu i cały `tenant-backup.sh` wołają `docker run --user 0:0
-v <wolumen>:/src:ro ... "ghcr.io/patrykgielo/registro:<tag>" sh -c "cp -a /src/. /dest/ && chown
..."` — **bez `--entrypoint`**. Ten obraz ma własny `docker/entrypoint.sh`, który odmawia startu
jako ktokolwiek inny niż `laravel` (`EXPECTED_USER` check, `❌ CRITICAL: Running as 'root' but
expected 'laravel'`) — `--user 0:0` nigdy nie docierał do `cp`/`chown`, entrypoint zabijał kontener
najpierw. `docker run` samo w sobie "kończyło się sukcesem" (kontener wystartował i wyszedł — tylko
z kodem 1, nic nie skopiowane), a otaczający `stage_volume()` tę porażkę łapał (`return 1`) —
skutek widoczny operatorowi to `DEGRADED` na KAŻDYM apply, dla KAŻDEGO tenanta, odkąd ten kod
istniał nietestowany przeciw prawdziwemu obrazowi — łatwe do przeczytania jako "restic jeszcze nie
zainstalowany", nie jako "wolumeny storage nigdy nie trafiły do snapshota".

**Naprawa:** `--entrypoint sh` + rozbicie komendy na `-c "..."` (teraz argument DLA `sh`, nie część
CMD, którego i tak nikt nie interpretował) — identyczny, jednowierszowy fix w obu plikach.
Zweryfikowane wprost przed i po: to samo `❌ CRITICAL` reprodukowane na oryginalnym kodzie; po
naprawie `cp -a`/`chown` faktycznie wykonane, `sha256sum` zgodna z oryginałem.

**Dlaczego wcześniejsza walidacja Fazy 2 (2026-08-09/10, ten sam plik, incydent "4 poprawki
blokujące... realny wolumen Dockera" wyżej) tego nie złapała:** ten test uruchomił
`stage_volume()` jako izolowaną funkcję przeciw JAKIEMUŚ wolumenowi Dockera, najwyraźniej nie
przeciw kontenerowi zbudowanemu z WŁASNEGO `Dockerfile`/`entrypoint.sh` tego projektu — generyczny
obraz nigdy nie trafiłby na ten guard. Walidacja Fazy 3 (pełna migracja tenanta między dwiema
symulowanymi maszynami) była pierwszym razem, gdy ta dokładna funkcja pobiegła z prawdziwym,
entrypoint-guarded obrazem `ghcr.io/patrykgielo/registro` jako kontenerem stagingowym — dokładnie
tym, którego używa każdy realny `apply`/cron backup na prawdziwym serwerze.

Pełny opis + cała walidacja migracji (6 punktów, `audit_logs`/`APP_KEY` przeżywa przeniesienie,
niezależna alokacja portów/podsieci na maszynie docelowej): `app/docs/deployment/
instalacja-tenanta-od-zera.md` → Część 9, `app/docs/deployment/tenant-apply.md` →
"`stage_volume()` bug found during Faza 3 validation".

**Zasada:** izolowany test funkcji, który podstawia inny obraz/kontener niż ten, którego produkcja
faktycznie używa, może przejść, mimo że funkcja jest złamana przeciw prawdziwemu artefaktowi. Gdy
kod robi `docker run` na WŁASNYM obrazie projektu (nie na `alpine`/`debian`/generycznym helperze) —
walidacja MUSI użyć tego samego, realnie zbudowanego obrazu, z jego prawdziwym entrypointem,
inaczej test dowodzi tylko, że powłoka wewnątrz działa, nie że cała ścieżka `docker run` działa.

---

## Incydent (znaleziony przez faktyczny drill, nie shipped): `tenant-restore.sh` — read side
## backupu nie istniał; drill nie znalazł bugów w nowym skrypcie, ale znalazł dwie pułapki metodyki

`scripts/server/tenant-restore.sh`, nowy. Do 2026-08-12 nic w repo nigdy nie CZYTAŁO snapshota
restica — jedyna procedura przywracania była prozą w `instalacja-tenanta-od-zera.md` Część 8 do
ręcznego przepisania. Pełny opis skryptu, tryby, i drill end-to-end (build lokalny obrazu, prawdziwy
6-kontenerowy stack, backup → zniszczenie CAŁEGO stacka → `--restore-live` → weryfikacja
`sha256sum`/odszyfrowania/własności): `app/docs/deployment/instalacja-tenanta-od-zera.md` → 8.0,
8.7a; `app/docs/deployment/tenant-apply.md` → sekcja `tenant-restore.sh`.

**Sam skrypt zadziałał poprawnie za pierwszym uruchomieniem na każdej testowanej ścieżce** (scratch
restore, `--restore-live`, cztery bramki bezpieczeństwa, override `RESTIC_PASSWORD_FILE`) — żaden
bug w `tenant-restore.sh` nie został znaleziony przez sam drill. Trzy testy (`tests/shell/cases/
13-15`) i tak dodane, bo pinują WŁASNOŚCI będące całą obietnicą bezpieczeństwa tego skryptu
(`--confirm-slug`, `--target-db` ≠ żywa baza, `chown -R 1000:1000`), nie regresje.

**Dwie pułapki znalezione w METODYCE walidacji, obie skorygowane przed dowiedzeniem czegokolwiek:**

1. **Model factory (`Organization::factory()`) nie działa na prawdziwym obrazie produkcyjnym.**
   `Call to undefined function Database\Factories\fake()` — `fakerphp/faker` to zależność
   `require-dev`, wycięta przez `composer install --no-dev` przy budowaniu obrazu. To POPRAWNE
   zachowanie obrazu produkcyjnego, nie bug — ale znaczy, że każdy przyszły drill na TYM obrazie
   musi zasadzać dane wprost przez `Model::create([...])` (z wszystkimi wymaganymi kolumnami,
   `owner_id`, `auditable_type`/`auditable_id` itd.), nie przez fabryki, inaczej wygląda na zepsuty
   model zamiast na oczekiwane ograniczenie środowiska.
2. **Fake `id -u`/`id -g` na `PATH` NIE symuluje innego hosta-operatora dla `tenant-backup.sh`'s
   `stage_volume()`.** Pierwsza próba dowiedzenia "backup pod innym UID operatora" podmieniła
   binarkę `id`, żeby `$(id -u):$(id -g)` w skrypcie zwracało `1002` — ale sam PROCES basha nadal
   biegł jako prawdziwy UID hosta (1000). Uprzywilejowany `docker run --user 0:0 ... chown -R
   1002:1002 /dest` faktycznie przestawił własność katalogu stagingowego na 1002, a NASTĘPNY krok
   skryptu (`rm -rf "$STAGE_DIR"` we własnym, nieuprzywilejowanym procesie, prawdziwy UID 1000) padł
   `Permission denied` na każdym pliku — wyglądające jak realny bug w `tenant-backup.sh`, ale będące
   artefaktem testu: w rzeczywistości `id -u` ZAWSZE prawdziwie zwraca UID wywołującego procesu,
   więc `chown` na tę wartość nigdy nie może rozjechać się z uprawnieniami tego samego procesu.
   Naprawa: nie fałszować `id`, tylko zbudować snapshot z zawartością OZNAKOWANĄ inną własnością
   wprost (`docker run --user 0:0 ... chown -R 1002:1002` na źródle), niezależnie od PATH-owych
   podmianek — to faktycznie testuje "co jeśli snapshot zawiera pliki z innym UID", bez fałszowania
   tożsamości procesu, która musi zostać spójna dla testu żeby cokolwiek dowodzić.

**Zasada:** brak znalezionych bugów w faktycznym uruchomieniu jest samo w sobie wynikiem wartym
zapisania (nie "nic się nie stało, pomiń") — odróżnia "przetestowane i działa" od "nieprzetestowane".
Przy próbie SYMULOWANIA innej tożsamości (UID, użytkownika) w teście powłoki: podmiana binarki
zwracanej przez `$(polecenie)` zmienia tylko STRING, którego skrypt użyje w kolejnej komendzie — nie
zmienia rzeczywistych uprawnień PROCESU, który tę komendę wykonuje. Jeśli oba muszą się zgadzać
(jak `chown` na wartość z `id -u`, potem operacja na tym samym pliku przez ten sam proces), fałszowanie
samego `id` tworzy rozjazd, którego produkcja nigdy nie zobaczy.

---

## Incydent (drugi przegląd tego samego dnia, cztery realne luki, wszystkie odtworzone w kodzie):
## `tenant-restore.sh` — zielony drill dowiódł happy path, nie ścieżek awaryjnych

Bezpośrednia kontynuacja incydentu powyżej. Pierwszy drill (ten sam dzień, 2026-08-12) przeszedł
end-to-end i doprowadził do 15/15 zielonych testów — ale drugi, niezależny przegląd odtworzył
CZTERY realne błędy w tej samej wersji skryptu, wszystkie w kodzie ścieżki `--restore-live`, żaden
z nich widoczny w happy-pathowym drillu, bo żaden test nie pinował SEKWENCJI wywołań, tylko same
bramki bezpieczeństwa (confirm-slug, target-db≠live, chown). Usunięcie CAŁEJ sekwencji trybu
konserwacji z kodu i tak dawało 15/15 — dowód wprost, że zielony end-to-end nie jest tym samym co
bezpieczne ścieżki awaryjne.

1. **SEVERE — tryb konserwacji zagnieżdżony wyłącznie w bloku bazy danych.** Dwie konsekwencje:
   `--restore-live --confirm-slug <slug> --skip-db` (zwykłe, udokumentowane wywołanie — guard
   odmawia tylko przy OBU flagach `--skip-*` naraz) wypakowywało pliki prosto do żywych wolumenów
   **bez żadnego trybu konserwacji w ogóle**; a na zwykłej ścieżce `artisan up` uruchamiał się
   PRZED wypakowaniem wolumenów — aplikacja wracała na ruch z bazą odwołującą się do
   zdjęć/logo/obrazów CMS wciąż w trakcie `tar -x`, dokładnie tej niespójności, przed którą ma
   chronić jeden snapshot restica (patrz `tenant-backup.sh`'s własny nagłówek).
2. **Nic nie bramkowało fazy plików awarią fazy bazy.** Nieudane wczytanie zrzutu logowało "app left
   in maintenance mode… fix manually", ustawiało flagę awarii, i i tak leciało dalej do wypakowania
   plików do żywych wolumenów.
3. **Brak pułapek na sygnał.** Jedyny trap to `rm -f "$DUMP"` na EXIT, rozbrajany w połowie skryptu —
   Ctrl-C, zerwane SSH albo timeout systemd w środku `artisan down`/wczytywania/`tar` zostawiały
   tenanta w trybie konserwacji albo z połowicznie nadpisanym wolumenem, bez żadnego zapisu
   tłumaczącego dlaczego. `apply.sh`'s `on_exit`/`on_signal` (patrz incydent wyżej w tym pliku,
   "drugi przegląd infrastrukturalny") istnieje dokładnie po to, a `tenant-restore.sh` go nie miało.
4. **`tenant-check.sh` mógł zgłosić fałszywie zdrowego tenanta.** `tenant-restore.sh` nigdy nie
   pisało do `STATE_DIR/apply-status`, a `tenant-check.sh` ufa temu plikowi jako źródłu prawdy —
   nieudany albo zabity live restore zostawiał tenanta zepsutego, podczas gdy status wciąż czytał
   się jako `OK` z ostatniego udanego `apply`.

**Naprawa:** JEDNO okno trybu konserwacji obejmuje OBIE fazy niezależnie od
`--skip-db`/`--skip-files`; faza plików NIGDY nie startuje po awarii fazy bazy; `on_exit`/
`on_signal` skopiowane z dokładnie tego samego wzorca co `apply.sh` (bezwarunkowy zapis `RUNNING`
w momencie wejścia w tryb konserwacji, `FAILED` z powodem przy każdej awarii/sygnale, `OK` dopiero
po pełnym sukcesie). **Jedna celowa różnica względem `apply.sh`:** `clear_maintenance()` w
`tenant-restore.sh` NIGDY nie próbuje sam wywołać `artisan up` (w przeciwieństwie do `apply.sh`'s
odpowiednika, który auto-leczy przerwaną migrację) — restore ma DWIE zależne fazy, a auto-leczenie
na przerwaniu, które wylądowało między nimi, ryzykowałoby dokładnie tę samą niespójność co błąd #1.
Człowiek potwierdzający ręcznie spójność bazy i plików przed wpisaniem `artisan up` jest tu
świadomie bezpieczniejszym domyślnym zachowaniem niż auto-czyszczenie — uzasadnione tym, że
`apply.sh`'s pojedynczy, niezależny krok migracji na auto-leczenie pozwala, a restore nie.

**Trzy nowe testy pinujące SEKWENCJĘ, nie same bramki** (`tests/shell/cases/16-18`) — każdy
dowiedziony czerwono-potem-zielono przez podstawienie DOKŁADNEJ starej (błędnej) wersji skryptu i
potwierdzenie, że test wywraca się dokładnie na opisanym problemie (test 16 złapał `artisan up`
przed plikami I brak zapisu `apply-status`; test 17 złapał brak `artisan down` przy `--skip-db`;
test 18 złapał wypakowanie plików po awarii bazy I brak zapisu `FAILED`).

**Druga, realna weryfikacja NAPRAWIONEJ wersji, nie tylko fejkami w `tests/shell/`** — dokładnie ta
sama lekcja co przy fejkowaniu `id` w incydencie wyżej: fejki dowodzą, że skrypt WYSYŁA właściwe
komendy we właściwej kolejności, nie że prawdziwy Docker/MySQL faktycznie tak się zachowa. Powtórzono
CAŁY drill (nowy build obrazu, świeży `git worktree`, sześć kontenerów) przeciw poprawionej wersji:
happy path pokazał w logu skryptu kolejność WPROST poprawną (baza → oba wolumeny → "Application is
now live"), `apply-status` → `OK`; prawdziwa awaria auth MySQL (podmienione `DB_ROOT_PASSWORD` w
`.env.secrets`, kontener wciąż z PRAWDZIWYM oryginalnym hasłem — realny `Access denied`, nie
symulowany) zatrzymała restore z kodem 3, zero linii o wypakowywaniu plików w logu, `horizon`/
`scheduler` nadal zatrzymane, `apply-status` → `FAILED`; przywrócenie poprawnego hasła i ponowienie
TEJ SAMEJ komendy w pełni odzyskało stan (kod 0, `apply-status` → `OK`).

**Zasada:** zielony end-to-end drill dowodzi, że happy path działa — nie dowodzi, że ścieżki
awaryjne (awaria w połowie, sygnał, plik statusu czytany przez inny skrypt) są bezpieczne. Test
pinujący SAMĄ BRAMKĘ (czy odmowa działa) i test pinujący SEKWENCJĘ (co się dzieje, gdy bramka
przepuści, a coś DALEJ zawiedzie) to dwie różne własności — pierwszy nie zastępuje drugiego. Po
naprawie znalezionej w REVIEW (nie w drillu) — powtórz przynajmniej skróconą wersję realnego drillu
na poprawionym kodzie, nie tylko testy jednostkowe z fejkami: fejki potwierdzają KOLEJNOŚĆ wywołań,
nie że prawdziwa infrastruktura tak zareaguje.

## Incydent 2026-08-12: lokalny nginx nie startował bez kontenera `node` — literalny `proxy_pass`/`fastcgi_pass` do nazwy kontenera

Obserwowane na żywo w tej sesji: `registro-node` (Vite dev server, `npm run dev`) wyszedł/nie
działał; `registro-nginx` był w pętli restartów, `curl` na `https://registro.local:8444` zwracał
exit 7 (connection refused). Log: `[emerg] host not found in upstream "registro-node"`.

**Przyczyna:** `docker/nginx/default.conf` miało `proxy_pass https://registro-node:5173;` zapisane
literalnie w dwóch lokacjach obsługujących Vite HMR (`/@vite/` i `~ ^/(resources|@id|node_modules)/`).
nginx rozwiązuje literalny upstream w `proxy_pass`/`fastcgi_pass` RAZ, przy starcie/reload configu —
brak kontenera w tym dokładnie momencie wywala start CAŁEGO pliku, nie tylko tych dwóch lokacji,
bo wszystkie dzielą jeden `server {}`. To zderzało się wprost ze standardową zasadą tego projektu
(`CLAUDE.md`): nigdy `npm run dev`, zawsze `docker compose exec -T app npm run build` — środowisko
było niemożliwe do uruchomienia we własnej sankcjonowanej konfiguracji.

Ten sam kształt błędu (osobny bug, nie ta sama linia) był w `docker/nginx/staging/app.staging.conf`:
`fastcgi_pass app:9000;` literalnie — brak kontenera `app` przy starcie/reload wywalał cały plik,
identyczny mechanizm jak wyżej, inny upstream.

**Naprawa:** wzorzec już istniejący w repo (`docker/nginx/production/app.prod.conf`,
`docker/nginx/edge/tenants.d/_example.conf.disabled`) — `resolver 127.0.0.11 valid=5s ipv6=off;`
(Docker's embedded DNS) + `set $upstream_x host:port;` + `proxy_pass $upstream_x;`/
`fastcgi_pass $upstream_x;`. Zmienna odracza rozwiązanie nazwy do czasu żądania: zatrzymany
kontener 502-uje TYLKO własne lokacje, reszta configu wstaje normalnie. Jedna pułapka do
sprawdzenia przy `proxy_pass`/`fastcgi_pass` z URI (nie tu, ale dla przyszłych podobnych fixów):
nginx nie potrafi w czasie parsowania rozstrzygnąć, czy zmienna zawiera część URI, więc zawsze
traktuje to jak "bez URI" — czyli oryginalny URI żądania leci bez zmian, dokładnie tak samo jak
literalny `proxy_pass host:port;` bez ścieżki (potwierdzone: [nginx docs, ngx_http_proxy_module,
sekcja proxy_pass](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_pass) — "if the
address is specified without a URI, or it is not possible to determine the part of URI to be
replaced, the full request URI is passed").

`app.staging.conf` ma DODATKOWO `resolver 8.8.8.8 8.8.4.4 valid=300s;` na poziomie `server` — dla
OCSP stapling, publiczny DNS, nie zna nazw kontenerów Dockera. `resolver` per-`location` nadpisuje
`resolver` z `server` TYLKO w tej lokacji — więc drugi `resolver 127.0.0.11 ...;` poszedł do środka
`location ~ \.php$`, zostawiając OCSP na publicznym DNS nietkniętym. Dodanie drugiego `resolver` w
TYM SAMYM kontekście (co próbowałem najpierw w `default.conf`, gdzie już był jeden na poziomie
`server`) kończy się `nginx: [emerg] "resolver" directive is duplicate` — złapane od razu przez
`nginx -t`/rzeczywisty restart, nie zostawione w kodzie.

**Zweryfikowane uruchomieniem, nie czytaniem:** `node` zatrzymany → `docker compose restart nginx`
→ kontener wstał czysto (bez `[emerg]`, bez pętli restartów), `curl` na `/` → 200, HTML odwołuje się
do `build/assets/...`, nie `@vite` (bo `public/hot` nie istniał — assety poprawnie zbudowane);
pobranie realnego pliku CSS/JS z `build/assets/` → 200 z poprawnym `content-type`; `/@vite/client`
→ 502, nginx nadal `Up` po tym żądaniu. `app`/`mysql`/`redis` NIE przebudowane (te same ID kontenerów
przed i po). `app.staging.conf` zweryfikowany WYŁĄCZNIE przez `nginx -t` w jednorazowym kontenerze
(bez certów Let's Encrypt, syntetyczny self-signed) — nie uruchomiony przeciw prawdziwemu ruchowi
staging, bo taki serwer w tej sesji nie istniał do przetestowania.

**Test — pierwsza wersja (grep) obalona w review, druga wersja (prawdziwy `nginx -t`) zastąpiła ją
całkowicie:** pierwsza wersja `tests/shell/cases/19_nginx_no_hardcoded_proxy_upstream.sh` była
statyczną analizą regexem `(proxy_pass|fastcgi_pass)\s+host:port;` nad całym drzewem
`docker/nginx/**/*.conf*`. Review odtworzyło DWA obejścia, oba nadal wywalające nginx dokładnie jak
oryginalny bug:
1. Spacja przed średnikiem (`proxy_pass https://host:port ;`) — regex wymagał `:[0-9]+;` bez
   spacji, więc formatowanie edytora go omijało.
2. Blok `upstream nodeup { server host:port; }` + `proxy_pass http://nodeup;` — zero trafień
   regexa. GORSZE niż pierwsze obejście: `resolve=` na linii `server` wewnątrz `upstream {}` to
   funkcja WYŁĄCZNIE nginx-plus, więc sztuczka ze zmienną, którą ta reguła rekomenduje jako
   naprawę, w ogóle nie da się tu zastosować — regex rekomendujący niedziałającą naprawę jest
   gorszy niż brak checka.

**Zasada (transferowalna, nie tylko dla tego pliku):** regex statyczny pinuje PISOWNIĘ dzisiejszego
błędu, nie WŁASNOŚĆ. Obie luki były pisownią, której regex nie przewidział. Gdzie prawdziwa
własność jest tanio wykonywalna (tu: `docker run --rm --network none ... nginx -t` — nginx albo
startuje, albo nie), wykonaj ją naprawdę zamiast przybliżać tekstowym dopasowaniem.

**Druga wersja — prawdziwe `nginx -t`:** case uruchamia `nginx:1.25-alpine` z `--network none`
(deterministycznie nierozwiązywalny upstream, offline, niezależnie od tego, jakie kontenery akurat
żyją na jakiejkolwiek sieci mostkowej) przeciw (1) obu prawdziwym, naprawionym plikom z repo —
muszą wystartować czysto — oraz (2) trzem syntetycznym, znanym-złym kształtom (literał oryginalny,
literał ze spacją, blok `upstream {}`) — każdy MUSI wywalić `nginx -t`. Dowiedzione
czerwono-potem-zielono na PRAWDZIWYCH plikach z repo (nie tylko syntetykach): `git stash` obu
naprawionych plików → FAIL, złapane dokładnie `default.conf` (`registro-node`) i
`app.staging.conf` (`app`); `git stash pop` → PASS. Wszystkie trzy syntetyczne złe kształty →
exit 1; naprawiona forma zmiennej → exit 0.

**Koszt czasowy — świadomy kompromis, nie przeoczenie:** `tests.md` twierdzi, że cały pakiet
działa "w dobrze poniżej sekundy, offline, bez prawdziwego demona Dockera". Ten JEDEN case łamie
to twierdzenie: `--network none` i tak dziedziczy `/etc/resolv.conf` HOSTA (kopiowany przez Dockera
nawet bez sieci), więc synchroniczny resolver libc dla literalnego upstreamu próbuje go realnie
osiągnąć zanim podda — ~5,2 s na zły kształt z gołym `--network none`. Nadpisanie
`/etc/resolv.conf` na `nameserver 127.0.0.1` + `options timeout:1 attempts:1` (adres loopback bez
nasłuchującego procesu, szybka porażka zamiast pełnego cyklu timeoutu) tnie to do ~1,2 s na zły
kształt. Cały case: ~4,5 s. Cały pakiet (27 pozostałych testów + ten, stan 2026-08-14): ~6,3 s
zamiast <2 s -- rosło z każdym dodanym niezależnym od tego case'u testem, ten sam realny koszt.
Uznane za wart tego kosztu, bo alternatywa (regex) dowiedziona jako przepuszczająca realne błędy,
jeden z nich niemożliwy do naprawienia wzorcem, który regex by "wykrył jako nieobecny".

**Sprawdzone dokumenty:** README.md i `docker-init.sh` (jedyna udokumentowana ścieżka "quick
start") uruchamiają `docker compose up -d --build` bez wskazywania serwisów — `node` wstaje
automatycznie, więc żaden udokumentowany happy path nie trafiał na ten bug wprost. Trafiał na niego
deweloper, którego `node` już nie działał (crash `npm install`, ręczne `docker compose stop node`,
albo — jak w tej sesji — kontener po prostu wyszedł) i który potem zrestartował/przebudował `nginx`.
`composer run dev` to osobna, nie-Dockerowa ścieżka (`npx concurrently` + `php artisan serve` +
`npm run dev` na hoście) — nie dotyka tego pliku w ogóle.

---

## Incydent 2026-08-15: pierwszy realny przebieg `deploy-production.yml` — bramka testowa nigdy
## nie stała na silniku bazy, na którym cokolwiek wdrażamy

`deploy-production.yml` istniało od dawna jako 300-linijkowy `workflow_dispatch`, ale
`gh run list --workflow=deploy-production.yml` pokazywał dokładnie JEDEN przebieg w całej
historii: dzisiejszy, pierwszy w ogóle wykonany, i padł na kroku "Run migrations and seeders":

```
2026_06_16_100002_add_analytics_virtual_columns .......... FAIL
SQLSTATE[42000]: 1064 ... near '>>'$.service_slug') VIRTUAL
```

### Przyczyna

`services.mariadb: image: mariadb:10.11` w bramce testowej, podczas gdy `docker-compose.prod.yml`
(to, na czym faktycznie stoi UAT) używa `mysql:8.0` — zweryfikowane z nagłówka zrzutu produkcji
(`mysqldump 10.13 Distrib 8.0.46`). Migracja z 16 czerwca (nie należąca do wydania, które utknęło
na tym blokerze) generuje kolumnę wirtualną przez `properties->>'$.service_slug'` — operator JSON
MySQL 8, którego MariaDB nie zna. Migracja była poprawna dla silnika, na którym faktycznie
wdrażamy; błędny był wybór silnika w samej bramce.

Ten sam mismatch (`mariadb:10.11` + `redis:7.0` zamiast `redis:7.2-alpine`) siedzi też w
`ci-staging.yml` i `test.yml` — NIE naprawione w tym samym diffie (świadomie, żeby nie mieszać
niezwiązanych plików), zostaje jako otwarty dług tej samej klasy.

### Rozwiązanie

`deploy-production.yml`: `mariadb:10.11` → `mysql:8.0`, `redis:7.0` → `redis:7.2-alpine` (oba
dokładnie dopasowane do `docker-compose.prod.yml`). Health-cmd, brak `-u` w `mysqladmin ping`,
`DB_USERNAME: root` — wszystko zostało bez zmian, bo zweryfikowane wprost, że działa identycznie
na `mysql:8.0`.

**Zweryfikowane realnym uruchomieniem (nie inspekcją), obie strony:**
- `mariadb:10.11`: dokładna DDL z migracji → `ERROR 1064 (42000) ... near '>>'`.
- `mysql:8.0`: ta sama DDL → sukces, exit 0.
- `root@%` istnieje domyślnie na `mysql:8.0` z samym `MYSQL_ROOT_PASSWORD` (`SELECT user,host FROM
  mysql.user` → `root | % | caching_sha2_password`) — TCP login jako root z hosta działa bez
  dodatkowych flag, obalając obawę o `caching_sha2_password`/`--default-authentication-plugin`
  (dotyczy tylko PHP <7.1.16/7.2.4, nieistotne dla PHP 8.3 tego projektu).
- PHP 8.3 `pdo_mysql` (ten sam obraz `app`, który uruchamia testy) połączył się z `mysql:8.0.46`
  (dokładnie ta sama wersja patch co produkcja) jako `root@%` przez `caching_sha2_password` bez
  żadnej dodatkowej konfiguracji.

**Test regresyjny:** `tests/shell/cases/30_deploy_production_db_engine_matches_prod.sh` — czyta
REALNE tagi obrazów z `deploy-production.yml` i `docker-compose.prod.yml` (nigdy na sztywno),
odpala prawdziwy kontener zadeklarowanego przez workflow silnika i przepuszcza przez niego DDL
wyciągnięty wprost z realnej migracji; negatywna kontrola na hardcoded `mariadb:10.11` musi
odrzucić tę samą DDL, inaczej test nie jest dyskryminujący. Dowiedzione czerwono-potem-zielono:
podstawienie starej wersji pliku (`mariadb:10.11`) daje czytelny FAIL z realnym błędem SQL w
komunikacie; przywrócenie fixu → PASS.

**Pułapka znaleziona przy pisaniu testu, nie w produkcyjnym kodzie:** pierwsza wersja ekstrakcji
obrazu przez `awk` dopasowywała luźne `/redis:\s*$/` bez kotwiczenia wcięcia — złapała `redis:`
zagnieżdżone w `depends_on:` (głębiej wcięte niż definicja serwisu) w `docker-compose.prod.yml` i
w rezultacie zwróciła obraz `mysql:8.0` jako rzekomy obraz redisa. Naprawa: kotwiczenie na
DOKŁADNYM wcięciu klucza serwisu najwyższego poziomu (`^  redis:$` w compose, `^      redis:$` w
workflow), nie samo `\s*`. Osobno: `mawk` (awk tej maszyny) nie zna `\s` — tylko klasy POSIX
(`[ \t]`), pierwsza wersja skryptu cicho nie dopasowywała niczego zamiast błędu.

### Zapobieganie

Ten sam wzorzec co incydent "6 bugów w `apply.sh`" i incydent nginx-upstream wyżej w tym pliku:
plik, który nigdy nie został faktycznie uruchomiony, nie jest zweryfikowaną infrastrukturą, tylko
jej opisem. `gh run list` przed zaufaniem dowolnemu `.github/workflows/*.yml` — zero przebiegów w
historii znaczy dokładnie to samo, co "nieprzetestowany kod": spodziewaj się więcej niż jednego
błędu przy pierwszym realnym uruchomieniu, nie tylko tego, który akurat zgłosił CI.

---

## Incydent 2026-08-15 (kontynuacja powyższego): silnik naprawiony, bramka i tak padała — 27
## failures niewidocznych na SQLite, trzy niezależne przyczyny + jeden zaszły w CI od zawsze błąd

Bezpośrednia kontynuacja incydentu powyżej (PR #187 naprawił `mariadb:10.11` → `mysql:8.0`). Ten
sam commit (`v0.13.0-rc11`): `docker compose exec app php artisan test` → 833 passed na SQLite,
zero failures. Bramka na `mysql:8.0` → **27 failed, 5 skipped, 806 passed**, 440s. Pełna analiza
mechanizmów + fix: `.claude/rules/tests.md` → "MySQL 8.0 gate — what SQLite hides" (nie duplikowane
tutaj). Skrót czterech przyczyn:

1. Raw `PRAGMA foreign_keys = OFF` (SQLite-only) w jednym teście — 1064 syntax error na MySQL.
2. Fixture wstawiająca `payments.status = 'verified'`, wartość spoza realnego enuma
   (`pending|success|failed|refunded`) — SQLite nie egzekwuje ENUM-a, MySQL odrzuca (1265).
3. **Realny, poważny mechanizm:** cztery pliki testowe miały `tearDown() { Mockery::close();
   parent::tearDown(); }` — odwrócona kolejność względem WŁASNEGO bezpiecznego `tearDown()`
   Laravela (który wywołuje `Mockery::close()` DOPIERO po rollbacku `RefreshDatabase`). Gdy
   oczekiwanie mocka nie zostało spełnione, `Mockery::close()` rzucał PRZED rollbackiem — cała
   transakcja testu (~150 wierszy z `RolePermissionSeeder`) zostawała otwarta na porzuconym
   połączeniu, trzymając blokadę na `permissions.name = 'settings.manage'` aż PHP-owy GC w końcu
   posprząta zerwany kontener aplikacji — dłużej niż jeden `innodb_lock_wait_timeout` (50s).
   KAŻDY kolejny test RefreshDatabase w CAŁYM pozostałym przebiegu (dowolna klasa) blokował się na
   pełne 50s zanim sam rzucił `SQLSTATE[HY000]: 1205`, kaskadowo przez kilka testów z rzędu.
4. **Faktyczny wyzwalacz #3, obecny w workflow od PIERWSZEGO commita repo, nigdy niezweryfikowany:**
   krok "Run PHPUnit tests" ustawiał `QUEUE_CONNECTION=redis`/`CACHE_DRIVER=redis`/`CACHE_STORE=redis`,
   nadpisując celowe `sync`/`array` z `.env.testing`, na którym oparte są setki istniejących testów
   (dokumentowane wprost w docblockach, np. `ProcessRentalReturnRemindersJobTest`). Pod `redis`
   każdy `ShouldQueueAfterCommit` po prostu czekał nieprzetworzony w Redisie zamiast wykonać się
   synchronicznie w teście.

**Naprawa:** krok "Run PHPUnit tests" w `deploy-production.yml` — `QUEUE_CONNECTION`/`CACHE_DRIVER`/
`CACHE_STORE` z powrotem na `sync`/`array` (ten krok legalnie nadpisuje tylko `DB_*`, żeby wskazać
efemeryczny `mysql:8.0` zamiast SQLite). 4 pliki testowe: usunięty szkodliwy `tearDown()` override
(bazowa klasa Laravela już bezpiecznie wywołuje `Mockery::close()`). 2 pliki testowe: naprawione
fixture/asercje (enum, PRAGMA→`Schema::disableForeignKeyConstraints()`). 2 kolejne pliki testowe:
naprawione osobne, niezwiązane założenia "zawsze SQLite" (`OrganizationSingletonLockMigrationTest`,
`TenantProvisioningGuardsTest`). Zweryfikowane realnym `mysql:8.0` (tymczasowy kontener, nigdy
dev-baza): 27/27 uprzednio failing testów → PASS pojedynczo i w grupie; pełny `--testsuite=Feature`
raz na końcu → **0 failed, 5 skipped (zaszłe, niezwiązane), 833 passed**.

**Zasada:** ten sam bug klasy "plik nigdy faktycznie nie uruchomiony" (patrz zasada incydentu
powyżej) — `QUEUE_CONNECTION: redis` siedziało w tym kroku od `4d20ef4` (initial commit), zero
przebiegów przez całą historię repo aż do tego dnia. Migracja z projektu-szablonu bez lokalnej
walidacji = dług, który czeka cicho aż ktoś faktycznie uruchomi ścieżkę. Osobno: `Mockery::close()`
w customowym `tearDown()` PRZED `parent::tearDown()` to wzorzec do unikania zawsze, niezależnie od
silnika bazy — Laravel już robi to bezpiecznie sam; SQLite tylko maskował konsekwencję.

---

## Incydent 2026-08-15: `v0.13.0-rc12` (hardening every service) crashlooped redis na UAT —
## dwuminutowa awaria produkcji, cofnięte do rc9

### Problem

Deploy `v0.13.0-rc12` na UAT dodał `security_opt: no-new-privileges:true` + `cap_drop: ALL` (+
selektywny `cap_add`) do każdej z sześciu usług w `docker-compose.prod.yml`. Redis wpadł w pętlę
restartów, log dosłownie:

```
find: ./appendonlydir: Permission denied
```

`docker compose up` przerwał z `dependency failed to start: container registro-redis is unhealthy`
— `app`, `nginx`, `horizon`, `scheduler` zostały w stanie `Created` i nigdy nie wystartowały. MySQL
wstał zdrowy. Operator cofnął do `rc9` ręcznie; strona stała ~2 minuty.

### Przyczyna

`redis:7.2-alpine`'s oficjalny `docker-entrypoint.sh` startuje jako root i, zanim zrobi `setpriv
--reuid redis --regid redis` (drop do uid 999), wykonuje `find . \! -user redis -exec chown redis
{} +` nad całym `/data`. Ten projekt sam ustawia `umask 0077` dla plików tworzonych przez
`redis-server` (ten sam skrypt, dalej w pliku) — więc `appendonlydir/`, katalog realnie utworzony
przez wcześniejszy, nie-hardenowany boot, ma tryb `0700`, właściciel `redis:redis`. `find` jako root
BEZ `CAP_DAC_OVERRIDE` podlega zwykłym bitom uprawnień tak samo jak każdy inny proces — `opendir()`
na katalogu `0700` nienależącym do niego (a root bez tej capability nie "jest" właścicielem w
sensie jądra) zwraca `EACCES`, `find` kończy się niezerowo, `set -e` w entrypoincie zabija kontener
PRZED jakimkolwiek `exec redis-server`.

`cap_drop: ALL` + `cap_add: [SETUID, SETGID]` (stan `rc12` przed poprawką) przeszedł przegląd
wcześniej, bo **działa bezbłędnie na świeżym wolumenie**: `redis:7.2-alpine`'s własny obraz ma
`/data` już własnościowo `redis:redis` w warstwie obrazu, a Docker zasiewa NOWY nazwany wolumen
zawartością obrazu przy pierwszym użyciu (zweryfikowane: `stat` na wolumenie, którego żaden
kontener jeszcze nie dotknął, pokazał `redis:redis` od razu) — `find` na takim wolumenie nie ma nic
do zrobienia, żadnego restrykcyjnego katalogu do wejścia. Luka otwiera się dopiero na wolumenie z
prawdziwą historią, a produkcyjny wolumen UAT miał wtedy trzynaście dni realnych danych.

### Rozwiązanie

Dodano `DAC_OVERRIDE` do `cap_add` redisa (`SETUID`, `SETGID`, `DAC_OVERRIDE`). `CHOWN` świadomie
NIE dodane — `chown redis {} +` w tym samym `find` jest no-opem w każdym realnym scenariuszu:
jądro (`chown_common()`) wymaga `CAP_CHOWN` tylko gdy docelowy UID faktycznie się zmienia, a
KAŻDY plik, po którym `find` chodzi na tym wolumenie — łącznie z samym punktem montowania `/data`,
sprawdzonym wprost `stat`em przed jakąkolwiek zmianą — jest już własnością `redis:redis`, albo z
wcześniejszego realnego boota, albo odziedziczoną z obrazu przy tworzeniu wolumenu. Żadna ścieżka
backup/restore w tym repo (`tenant-backup.sh`/`tenant-restore.sh`) nigdy nie dotyka `redis_data`,
więc nie ma kodu, który mógłby zostawić plik należący do roota.

**Zweryfikowane realnym uruchomieniem, nie inspekcją, oba kierunki (bug i fix), na tym samym
wolumenie z REALNĄ historią** (nie syntetycznymi plikami — organiczny boot, `SET`, `BGREWRITEAOF`,
`appendonlydir` powstały pod prawdziwym umaskiem tego projektu):
- Stary spec (`SETUID`+`SETGID`) → `find: ./appendonlydir: Permission denied`, dosłownie jak w
  incydencie.
- Nowy spec (+ `DAC_OVERRIDE`) → realne klucze wczytane (`DB loaded from append only file`),
  uwierzytelnione `PING` → `PONG`, `SAVE` (zapis NOWEGO pliku wprost do `/data`, ścieżka
  nieprzetestowana samym odczytem) → sukces.
- Ten sam test na GENUINELE świeżym wolumenie (nigdy nie dotkniętym) → sukces bez `DAC_OVERRIDE`
  ani `CHOWN`, potwierdzając dlaczego pierwotna weryfikacja rc12 nie złapała błędu.

**Cały stack, na realnym obrazie `ghcr.io/patrykgielo/registro:v0.13.0-rc12` (pull, nie build
lokalny), pod `docker-compose.prod.yml` niezmodyfikowanym poza tą jedną poprawką** — wszystkie
sześć usług doszło do `healthy` (`scheduler` do `Up`, brak healthchecka), realna migracja
(`php artisan migrate --force`) przeszła, realny `curl` przez hardenowany `nginx` do hardenowanego
`app` zwrócił `200` na `/up` i na pełnym widoku Blade. Zweryfikowano też negatywnie na poziomie
CAŁEGO stacka (nie tylko pojedynczego `docker run` na redisie): cofnięcie `cap_add` redisa do
starego spec + `docker compose up -d` na tym samym stacku odtworzyło DOKŁADNIE `dependency failed
to start: container hxtest-redis is unhealthy`, z `app`/`horizon`/`nginx`/`scheduler` w `Created`
— ten sam obraz awarii co na UAT.

**`app`, `horizon`, `scheduler`** (ten sam obraz, ten sam non-root `laravel` user od
Dockerfile:228) potwierdzone bez potrzeby jakiegokolwiek `cap_add` — re-zweryfikowane niezależnie
od istniejącego komentarza w pliku (nie zaufane samo w sobie), bo klasa błędu, która złamała redisa
(runtime chown/permission sweep trafiający w stan wolumenu z historią), fizycznie nie istnieje w
tym entrypoincie: brak jakiegokolwiek `find`/`chown` w `docker/entrypoint.sh`, własność `laravel:
laravel` zapieczona w obrazie (Dockerfile:222-226) i przypięta na UID 1000 od ADR-013, sprzed
istnienia hardeningu.

**`nginx`** (`NET_BIND_SERVICE`, `CHOWN`, `SETUID`, `SETGID`) potwierdzone jako już minimalne, nie
nadmiarowe — usunięcie `CHOWN` reprodukuje `nginx: [emerg] chown("/var/cache/nginx/client_temp",
101) failed (1: Operation not permitted)` za każdym razem, bo nginx inicjalizuje swoje katalogi
tymczasowe bezwarunkowo przy starcie, niezależnie od tego czy `proxy_cache` jest gdziekolwiek
skonfigurowany (w tym vhoście — `app.prod.conf` — nie jest).

**`pids_limit`** sprawdzony pod kątem forków Horizona (`config/horizon.php`'s production
`maxProcesses: 10`): realny pomiar na żywym, hardenowanym kontenerze pokazał 8 procesów w spoczynku,
limit `256` zostawia szeroki margines do skalowania w górę. Nie zmieniony.

### Test regresyjny

`tests/shell/cases/31_redis_hardening_survives_existing_appendonlydir.sh` — buduje wolumen z
REALNĄ historią (organiczny boot + `SET` + `BGREWRITEAOF`, nie ręcznie pisane pliki: katalog
zrobiony `mkdir`+`echo` dziedziczy umask powłoki testowej, 0755, i NIGDY nie odtwarza buga),
ekstraktuje `cap_add` redisa wprost z `docker-compose.prod.yml` (nigdy na sztywno), z syntetycznym
starym spec (`SETUID`+`SETGID`) jako negatywną kontrolą. Dowiedzione czerwono-potem-zielono:
`git stash` tego pliku → dokładny `find: ./appendonlydir: Permission denied` z komunikatem testu;
`git stash pop` → PASS.

### Zapobieganie

**Ten sam wzorzec co incydent `stage_volume()` (Faza 3, wyżej w tym pliku) i incydent nginx-upstream
(2026-08-12, wyżej): weryfikacja na świeżym/syntetycznym stanie nie dowodzi niczego o zachowaniu na
stanie z historią**, gdy operacja (tu: `find`+`chown` jako root, tam: `docker run` na własnym
obrazie z jego prawdziwym entrypointem) jest wrażliwa na to, co konkretnie już leży na dysku. Przy
hardeningu KAŻDEJ usługi w compose naraz — zweryfikuj każdą osobno przeciw stanowi, jaki miałaby
NAPRAWDĘ na produkcji (wolumen z danymi, nie pusty), nie przeciw temu, co najwygodniej postawić w
piaskownicy. "Działa na fresh volume" i "działa" to dwa różne twierdzenia, i tylko drugie ma
znaczenie dla usługi, która nigdy nie startuje od zera na żywym serwerze.

---

## Incydent 2026-08-15: `deploy.sh` — instalacja NOWEJ wersji na UAT padała na `status`, jedynej
## bezpiecznej diagnostyce jaką daje forced command, dokładnie na konfiguracji legacy

### Problem

Nowa wersja `deploy.sh` (task 4, stack-per-tenant) zainstalowana na UAT. Najbezpieczniejsza możliwa
akcja, `status`, zwracała kod 1 i zero wyjścia. `bash -x` zatrzymał się dokładnie tu:

```
+ case "${ACTION:-}" in
++ grep -m1 '^TENANT_PREFIX=' /var/www/registro/.env
++ cut -d= -f2-
+ prefix=
```
i dalej nic, mimo że kolejna linia to `docker ps`.

### Przyczyna

`deploy.sh` ma `set -euo pipefail`. Linia
```bash
prefix="$(grep -m1 '^TENANT_PREFIX=' "${APP_DIR}/.env" 2>/dev/null | cut -d= -f2-)"
```
przy **braku** klucza `TENANT_PREFIX` w `.env` (nie pustej wartości — braku linii w ogóle): `grep`
zwraca 1 (brak dopasowania), `cut` zwraca 0, `pipefail` bierze WYŻSZY z obu — pipeline zgłasza 1,
`set -e` zabija skrypt w tym miejscu, przed jakimkolwiek `docker ps`. **Brak `TENANT_PREFIX` to
dokładnie stan legacy** (`deployment.md`: „`TENANT_PREFIX=` PUSTE na legacy") — skrypt padał w
jedynej konfiguracji, w jakiej dziś realnie działa. UAT miał ten klucz nieustawiony —
zweryfikowane. Wzorzec wystąpił DWA razy w tym samym pliku: akcja `status` (linia ~100) i
`force_clear_flag()` (linia ~201, recovery function dla utkniętej flagi `maintenance.php`).

Przeszukanie CAŁEGO `scripts/server/` (`grep -n '\$(' *.sh | grep '|'`) pokazało, że **wszystkie
pozostałe pliki** (`apply.sh`, `sync-certificate.sh`, `tenant-backup.sh`, `tenant-check.sh`,
`tenant-restore.sh`, `check-certificate-expiry.sh`) już mają `|| true` na dokładnie tym samym
kształcie `grep -m1 '^KEY=' .env | cut -d= -f2-` — bug był izolowany do `deploy.sh`, jedynego pliku
gdzie ten wzorzec pojawił się bez ochrony.

### Rozwiązanie

`|| true` na SAMYM PODSTAWIENIU (`cut -d= -f2- || true`), nie na całej linii przypisania — zgodne z
konwencją już istniejącą w pięciu innych plikach `scripts/server/`. Brak klucza staje się legalnym
`prefix=""`, konsumowanym przez istniejący `${prefix:-registro}` fallback w obu miejscach —
identyczne zachowanie do „dziś" (przed refaktorem na per-tenant stacki), tylko bez zabijania
skryptu po drodze.

**Zweryfikowane realnym uruchomieniem, oba kierunki, oba wystąpienia:** `.env` bez linii
`TENANT_PREFIX` pod gołym `bash -c 'set -euo pipefail; ...'` — stara linia: exit 1, zero wyjścia
(dokładna reprodukcja objawu z UAT); nowa linia: exit 0, `prefix=[]`. Cały skrypt `status` z
prawdziwym `case` z pliku (nie kopią) i fakenym `docker` na PATH: stara wersja zabija się PRZED
`docker ps`; naprawiona dochodzi do `docker ps -a --filter name=registro-` i `exit 0`.

### Pułapka metodyczna znaleziona przy pisaniu testu regresyjnego, nie w kodzie produkcyjnym

Pierwsza wersja testu dla `force_clear_flag()` łapała kod wyjścia przez
`RC=0; ( set -euo pipefail; eval "$FN_SRC"; force_clear_flag ) || RC=$?` — i **przechodziła nawet
przeciwko niepoprawionemu źródłu**, mimo identycznej logiki wewnątrz. Przyczyna udokumentowana
wprost w manualu bash (`set`, opis `-e`): *"If a compound command ... sets -e while executing in a
context where -e is ignored, that setting will not have any effect until the compound command ...
completes."* Prawa strona `||` jest DOKŁADNIE takim ignorowanym kontekstem — `set -e` WEWNĄTRZ
podpowłoki, która sama jest operandem `||`, jest więc kompletnym no-opem, niezależnie od tego, że
został ustawiony jawnie w pierwszej linii tej podpowłoki. Naprawa: podpowłoka jako samodzielna
instrukcja (`( set -euo pipefail; ...; force_clear_flag )` na własnej linii), kod wyjścia
odczytywany osobną instrukcją `RC=$?` zaraz po niej — nigdy jako operand `||`/`&&`.

### Zapobieganie

**Ten sam mechanizm co incydent nginx-upstream i incydent `stage_volume()` wyżej w tym pliku: linia
wygląda poprawnie przy czytaniu, wyłapało ją dopiero uruchomienie.** `grep -m1 '^KEY=' plik | cut
...` pod `pipefail`, bez `|| true`, zabija skrypt dokładnie wtedy, gdy KEY jest opcjonalny i legalnie
nieobecny — czyli dokładnie w przypadku, po który ten wzorzec sięga się najczęściej (odczyt
pojedynczego, opcjonalnego klucza z `.env` bez `source`). Przy dodawaniu NOWEGO takiego odczytu w
`scripts/server/**`: `|| true` na podstawieniu jest domyślną, już ustaloną konwencją tego katalogu —
sprawdź nowy `grep | cut` przeciw `.env` bez tego klucza, nie tylko z pustą wartością klucza (dwa
różne stany, tylko pierwszy odtwarza tę klasę błędu). Osobno, dla samych testów: przy łapaniu kodu
wyjścia podpowłoki z jawnym `set -e` w środku — NIGDY jako operand `||`/`&&`, zawsze jako
samodzielna instrukcja z `RC=$?` zaraz po niej.

---

## Incydent 2026-08-15 (kontynuacja incydentów z tego samego dnia wyżej): ten sam rozjazd silnika
## siedział w TRZECH workflowach naraz — dwa z nich mają zero przebiegów do dziś

`deploy-production.yml`'s `mariadb:10.11`→`mysql:8.0`/`redis:7.0`→`redis:7.2-alpine`/
`QUEUE_CONNECTION: redis`→`sync` (incydenty wyżej, PR #187/#188) okazał się skopiowany identycznie
do `ci-staging.yml` i `test.yml` — nie oddzielnymi błędami, tym samym z projektu-szablonu, wklejonym
trzy razy. `gh run list --workflow=ci-staging.yml` i `--workflow=test.yml` → oba puste, zero
przebiegów w całej historii repo. Odkryte tym samym mechanizmem co incydent `deploy-production.yml`
wyżej (pierwsze realne uruchomienie znalazło błąd), ale odwrotnie: dla tych dwóch plików nie było
żadnego uruchomienia — błąd znaleziono przez ANALOGIĘ do pliku, który dopiero co padł, nie przez
własny przebieg. To znaczy, że naprawa jest **niezweryfikowana na tych dwóch plikach w GitHub
Actions** — zweryfikowana lokalnie (patrz niżej), ale nie w środowisku runnera.

**Naprawa (identyczna w obu plikach, ten sam komentarz co `deploy-production.yml`):**
`mariadb:10.11` → `mysql:8.0`, `redis:7.0` → `redis:7.2-alpine` w `services:`; krok testów:
`QUEUE_CONNECTION`/`CACHE_DRIVER`/`CACHE_STORE` z `redis` na `sync`/`array` (dopasowane do
`.env.testing`), `REDIS_HOST`/`REDIS_PORT` usunięte jako martwe (nic ich już nie czyta z tym
sterownikiem). Rozróżnienie zachowane wprost w komentarzu: silnik **bazy** ma odpowiadać produkcji
(decyduje o poprawności testowanego kodu — MariaDB nie zna operatora `->>`), sterownik **kolejki**
ma odpowiadać `.env.testing` (decyduje o determinizmie testu — pod `redis`
`ShouldQueueAfterCommit` nie wykonuje się synchronicznie).

**Zweryfikowane realnym uruchomieniem `test.yml`'s dokładnych kroków lokalnie** (efemeryczne
`mysql:8.0`+`redis:7.2-alpine` w Dockerze, obraz `app-app:latest` z `--network host`, repo
skopiowane do piaskownicy z `vendor/` podpiętym bind-mountem read-only z realnego katalogu —
NIE dev-stack, osobne kontenery, posprzątane po teście): `php artisan migrate --force` przeszło
WSZYSTKIE migracje, łącznie z `2026_06_16_100002_add_analytics_virtual_columns` (dokładnie tą, która
odrzucała `->>` na MariaDB w incydencie `deploy-production.yml`) — `DONE` na `mysql:8.0`. Zamiast
pełnego apartamentu Feature (już zweryfikowanego dziś w prawdziwym CI na `deploy-production.yml`,
833 passed) — wąski `--filter=ProcessRentalReturnRemindersJobTest` (9 testów jawnie zależnych od
synchronicznego `ShouldQueueAfterCommit`) uruchomiony DWA RAZY na tym samym kontenerze: pod
`QUEUE_CONNECTION=redis` (stara, błędna wartość) → **5 z 9 failed** (powiadomienia odroczone do
Redisa, nigdy nie wykonane w teście); pod `QUEUE_CONNECTION=sync` (naprawiona wartość) →
**9/9 passed**. Czerwono-potem-zielono na PRAWDZIWEJ przyczynie, nie tylko na literalnej zmianie
YAML.

**Co POZOSTAJE niezweryfikowane, wprost:** `ci-staging.yml` **nie da się** sprawdzić end-to-end —
`deploy` job celuje w `docker-compose.staging.yml` i sekrety `STAGING_VPS_*` na maszynie PreProd,
która nie jest kupiona. Test/build/gate część tego pliku ma identyczny kształt do `test.yml`
(zweryfikowany lokalnie jak wyżej, te same kroki), ale sam plik jako całość nigdy nie przebiegł w
GitHub Actions i nadal nie przebiegnie, dopóki maszyna PreProd nie istnieje. Nie zaokrąglać tego do
"zweryfikowane" — zweryfikowany jest wzorzec silnika/sterownika, nie ten konkretny plik w tym
konkretnym środowisku.

### Zapobieganie

Ten sam wzorzec co "6 bugów w `apply.sh`" i oba incydenty `deploy-production.yml` wyżej: plik bez
przebiegów w historii nie jest zweryfikowaną infrastrukturą. **Dodatkowo tutaj:** gdy jeden plik z
tej klasy okazuje się zepsuty, przeszukaj CAŁY `.github/workflows/` pod kątem TEGO SAMEGO
skopiowanego bloku (`services:`, wersje obrazów, override'y env) — kopiowanie z projektu-szablonu
oznacza, że błąd prawie na pewno nie jest odosobniony. `gh run list --workflow=<plik>` przed
zaufaniem KAŻDEMU workflow, nie tylko temu, nad którym akurat się pracuje.

---

## 2026-08-16: koszt minut Actions — cache warstw Dockera, usunięcie `ci-staging.yml`, `skip_tests`

Kontynuacja optymalizacji zapoczątkowanej PR #192 (seedery raz na proces, test job 214s→80s).
Trzy zmiany w `deploy-production.yml` + usunięcie martwego pliku, wszystkie w jednej sesji.

### 1. `ci-staging.yml` usunięty jako potwierdzony martwy kod

Zero przebiegów w całej historii Actions (`gh run list --workflow=ci-staging.yml`), sekrety
`STAGING_VPS_*` nigdy nie istniały (`gh secret list` → tylko `HEALTH_CHECK_HOST` jako zmienna,
jedno środowisko `production`), a maszyna PreProd, w którą celuje jego `deploy` job, nie jest
kupiona. Dokładnie ten sam wniosek co incydent 2026-08-15 wyżej ("ten sam rozjazd silnika... dwa z
nich mają zero przebiegów do dziś") już sygnalizował, tylko wyciągnięty do końca. Referencje
zaktualizowane: `.claude/agents/devops-engineer.md` (lista workflow), `production-readiness-checklist.md`
(dopisek superseding, nie przepisanie historii), `.github/workflows/RELEASE_PROCESS.md` (przepisany
całościowo — opisywał model push-tag-triggeruje-deploy, nieprawdziwy od dawna, sprzed komentarza
"Disabled for Registro migration" w każdym pliku workflow).

### 2. Cache warstw Dockera w `build` — `type=gha`, nie `type=registry`

`docker build --no-cache --pull` zamieniony na `docker/build-push-action` z
`cache-from/cache-to: type=gha,scope=registro-image,mode=max,ignore-error=true` (wymaga
`docker/setup-buildx-action` — sterownik `docker` domyślny na runnerach nie eksportuje żadnego
cache backendu poza inline).

**Rozstrzygnięcie `gha` vs `registry`, i dlaczego NIE jest to policzone, tylko uzasadnione
logicznie:** ten pipeline buduje WYŁĄCZNIE z `workflow_dispatch` na git TAGU (`inputs.version`),
nigdy z pusha na branch. Reguła dostępu GitHuba do cache Actions to udokumentowane "bieżący ref,
ref bazowy, branch domyślny" (docs.docker.com/build/cache/backends/gha/) — **niezweryfikowane**,
czy tag ref liczy się jako własny, trwale odizolowany zakres (co zrobiłoby ten cache bezużytecznym
między wydaniami) czy trafia pod fallback brancha domyślnego. `type=registry` nie ma tej
niejednoznaczności (zwykły OCI pull po nazwie, bez ACL na ref) — ale requiruje własny cleanup w
GHCR. `cleanup-cache.yml` w repo już celuje w schemat `buildcache-*` (dokładnie tę architekturę) —
zweryfikowane, że nigdy nic go nie zasiliło (`gh api .../packages/container/registro/versions` →
zero tagów `buildcache-*`), więc nie jest to w konflikcie z wyborem `gha`, tylko pozostaje martwym,
niedotkniętym kodem sprzed tej decyzji. `type=gha` wybrany, bo tania pomyłka: brak trafienia cache
kosztuje dokładnie tyle, co dziś (3,3 min), zero wpływu na storage GHCR, zero nowego cleanupu do
utrzymania. Darmowy limit 10 GB/repo z automatyczną eksmisją LRU (bez ryzyka billingu na koncie bez
Pro/Team/Enterprise — zweryfikowane z changelogu GitHuba z 2025-11-20) pasuje do „użytkownik płaci z
własnej kieszeni" lepiej niż rosnący prywatny storage w GHCR.

**Diagnostyka do wykonania PRZY PIERWSZYCH DWÓCH realnych dispatchach** (nie da się tego
zweryfikować bez faktycznego uruchomienia na GitHubie, którego ta sesja nie wykonała — zakaz
dispatchowania workflowów): dwa różne tagi wersji pod rząd, log kroku "Build and push image" w
DRUGIM przebiegu. Warstwy `apt-get`/`docker-php-ext-install`/`composer install`/`npm ci` oznaczone
`CACHED` → działa międzytagowo zgodnie z projektem. Brak `CACHED` na żadnej → ACL GitHuba
skopował cache per-tag, zero zysku dla tego kształtu pipeline'u — fallback to `type=registry` z
JEDNYM reużywanym tagiem (np. `buildcache-latest`) + `provenance: false`, żeby ograniczyć
powierzchnię nietagowanych manifestów do wyczyszczenia.

**`provenance: false`/`sbom: false`** dodane celowo — `docker/build-push-action` domyślnie
generuje adnotacje supply-chain jako dodatkowe nietagowane obiekty w TYM SAMYM pakiecie GHCR co
obrazy wydań; bez potrzeby na wewnętrznym pipeline deployu, i upraszcza że jedyne nietagowane
śmieci, jakie ten job może wytworzyć, to wpisy w cache Actions (sprzątane automatycznie przez
GitHub), nie coś w GHCR wymagające ręcznego czyszczenia.

**Zysk build joba: NIE zmierzony, tylko oszacowany logiką warstw Dockerfile.** Warstwy
`apt-get install`+`docker-php-ext-configure/install`+`pecl install redis` (linie 37-71) leżą NAD
`COPY composer.json`/`COPY . .` i nie zależą od kodu aplikacji ani lockfile'ów — historycznie
najwolniejsza część obrazów PHP (kompilacja rozszerzeń) jest cache-eligible przy KAŻDYM buildzie,
niezależnie od tego co się zmieniło w release. Warstwy `composer install`/`npm ci` cache-eligible
tylko gdy `composer.lock`/`package-lock.json` niezmienione między wydaniami — częste, ale nie
gwarantowane. Bez pomiaru nie da się podać liczby; oczekiwanie to zauważalne, ale niepewne co do
wielkości skrócenie 3,3 min buildu przy niezmienionych lockfile'ach, bliskie zeru gdy się zmieniają
— nigdy gorsze niż dziś (`ignore-error=true` na cache-to, `cache-from` samo w sobie nie może
spowolnić buildu poniżej stanu bez cache).

### 3. `skip_tests` — bramkowany podwójnym potwierdzeniem, nie gołym boolean

Rozważone i odrzucone: (a) goły boolean z ostrzeżeniem w logu — zbyt łatwy do przypadkowego
zaznaczenia przy ponownym dispatchu z zapamiętanymi wartościami UI; (b) automatyczne sprawdzenie
"czy ten tag miał już udany przebieg testów" przez `gh run list`/API — odrzucone, bo dopasowanie po
NAZWIE taga jest kruche jeśli tag zostanie kiedykolwiek przestawiony na inny commit, a automatyczna
zielona lampka to dokładnie ten rodzaj "wygląda bezpiecznie samo z siebie" mechanizmu, przed którym
ta flaga ma chronić operatora, nie zastępować jego osąd.

Wybrane: `skip_tests: boolean` (default `false`) + `skip_tests_confirm: string` (default `''`),
musi DOKŁADNIE zgadzać się z `inputs.version` żeby pominięcie faktycznie zadziałało — wzorzec
identyczny do `tenant-restore.sh --confirm-slug` już w tym repo. Nowy job `preflight` (bez
`needs`, zawsze biegnie, ~5s) jest jedynym miejscem porównania — `test`/`build`/`deploy` czytają
`needs.preflight.outputs.skip_tests`, żadne z nich nie duplikuje logiki porównania.

**Fail-safe, nie fail-shrink, zastosowane wprost:** `skip_tests=true` z NIEPASUJĄCYM
`skip_tests_confirm` (literówka, pusty, zły tag) → `preflight` kończy się `::error::` i exit 1,
CAŁY przebieg pada w sekundach, PRZED jakimkolwiek checkoutem/buildem. Świadomie NIE spada cicho z
powrotem na "uruchom testy" — to byłoby bezpieczne dla kodu, ale operator, który poprosił o
pominięcie i dostał zamiast tego wolniejszy, ale w końcu zielony deploy, nie ma żadnego sygnału że
jego flaga nie zadziałała.

**Skipnięty `test` job wymagał jawnych `if:` w `build`/`deploy`.** Domyślna semantyka `needs:` bez
`if:` odpowiada `success()` — job, który zgłasza się jako `skipped` (bo jego własny `if:` był
`false`), NIE liczy się automatycznie jako spełniający zależność; bez jawnego
`needs.test.result == 'success' || needs.test.result == 'skipped'` przy KAŻDYM
`skip_tests=true` przebiegu `build`/`deploy` po prostu nigdy by się nie uruchomiły, cicho. Nie
zweryfikowane realnym uruchomieniem na GitHubie (zakaz dispatchowania) — zweryfikowane wyłącznie
przez dokumentację GitHuba o zachowaniu `needs`/`success()` przy jobach skipniętych; pierwszy realny
dispatch z `skip_tests=true` jest jedynym sposobem na dowiedzenie tego wprost.

### Zapobieganie

Przy DOWOLNYM nowym `if:` warunkującym job w łańcuchu `needs:` — sprawdź, czy downstream jobs mają
WŁASNY jawny `if:` obejmujący `.result == 'skipped'`, nie polegaj na domyślnym `success()`. Przy
cache Dockera w pipeline triggerowanym WYŁĄCZNIE tagami (nie branchami) — sprawdź dokumentację ACL
wybranego backendu cache pod kątem zakresu per-ref, zanim obiecasz przyspieszenie; jeśli nie da się
zweryfikować bez realnego dispatcha, wybierz backend, którego najgorszy przypadek to "brak zysku",
nie "cichy koszt" (tu: `type=gha` nad `type=registry`, z tego samego powodu co
`ignore-error=true` na `cache-to` — cache to optymalizacja, nigdy nie powinna umieć zablokować albo
spowolnić poniżej baseline realnego deployu).

### Poprawka tego samego dnia (przegląd koordynatora, przed shippingiem): `preflight` sam dokładał
### stały koszt do KAŻDEGO wdrożenia — dokładnie temu, co miało zadanie ciąć

Punkt 3 powyżej opisuje pierwszą wersję `skip_tests` — osobny job `preflight` jako jedyne miejsce
porównania boolean+confirm, z twardym `::error::`+`exit 1` przy niezgodności. Odesłane w code
review PRZED mergem, nie znalezione przez uruchomienie.

**Problem, którego nie uwzględniła pierwsza wersja:** GitHub Actions nalicza KAŻDY job z
zaokrągleniem w górę do pełnej minuty, niezależnie od realnego czasu trwania. `preflight` wykonywał
się w kilkanaście sekund, ale liczył się jako minuta — **przy każdym wdrożeniu, także tym z
`skip_tests=false`**, czyli w zdecydowanej większości dispatchy. W zadaniu, którego celem jest
obcięcie rachunku za minuty Actions, stały dodatkowy job dokładał minutę do KAŻDEGO przebiegu, żeby
obsłużyć przypadek używany rzadko — zjadając część zysku z cache'u warstw (punkt 2 powyżej).

**Naprawa:** `preflight` usunięty całkowicie. Warunek pominięcia wyrażony wprost w `if:` joba
`test` (`!(inputs.skip_tests == true && inputs.skip_tests_confirm == inputs.version)`), bez
pośrednictwa osobnego joba/outputów. **Fail-safe zamiast fail-loud jako świadoma zmiana, nie tylko
efekt uboczny usunięcia joba:** niezgodność potwierdzenia teraz oznacza "testy się wykonują"
(dokładnie domyślne zachowanie `skip_tests=false`), zamiast wywalać CAŁY przebieg przed
jakimkolwiek buildem. To strefa lepsza na dwóch osiach naraz, nie kompromis: operator z literówką w
`skip_tests_confirm` dostaje pełny, bezpieczny deploy zamiast zera — bezpieczniejsze zachowanie
(uruchom testy) było o krok, a stara wersja i tak do niego nie spadała, tylko przerywała wszystko.
Głośność zachowana, przeniesiona tam, gdzie nic nie kosztuje: krok "Report skip_tests outcome" w
istniejącym jobie `build` (uruchamia się zawsze, gdy `test` zakończył się sukcesem lub został
pominięty — czyli w każdym scenariuszu poza realną, niezwiązaną porażką testów), emitujący
`::warning::` w dwóch sytuacjach: testy faktycznie pominięto, LUB poproszono o pominięcie a
potwierdzenie się nie zgadzało i testy poszły mimo to. Krok wewnątrz już-uruchamianego, już-płatnego
joba nie dokłada osobnej zaokrąglonej minuty — to nie optymalizacja tej samej klasy co osobny job,
to zupełnie inna kategoria kosztu (sekundy wewnątrz ~3-minutowego joba, nie nowy wpis w billing).

**Co POZOSTAJE niezweryfikowane, tak jak w wersji z `preflight`:** czy `needs.test.result ==
'skipped'` faktycznie zachowuje się jak udokumentowano (build/deploy uruchamiają się mimo
pominiętego `test`) — nie do sprawdzenia bez realnego dispatcha (zakaz dispatchowania w tej
sesji). Zmiana architektury (jeden job mniej) nie zmienia tego, co dokumentacja GitHuba twierdzi o
`needs`/skipped jobs — tylko usuwa TRZECIE miejsce (`needs.preflight.result`), które musiałoby się
zgadzać.

**Zasada:** przy projektowaniu bramki, która uruchamia się RZADKO (tu: `skip_tests=true`) —
policz koszt WSPÓLNEJ ścieżki (częsty przypadek), nie tylko koszt samej bramki. Osobny job do
walidacji dwóch stringów wygląda tanio przy czytaniu kodu — jest tani per-job, ale w GitHub
Actions minimalna jednostka rozliczeniowa to cały job, nie krok, więc "tani" job i tak kosztuje
pełną zaokrągloną minutę, płaconą przez WSZYSTKIE wywołania, nie tylko te korzystające z bramki. Gdy
walidacja może zamiast tego żyć jako `if:` na już-istniejącym jobie (lub krok wewnątrz
już-istniejącego, już-płatnego joba) — to jest tańsze z definicji, niezależnie od tego, jak mało
faktycznej pracy wykonuje sam osobny job.
