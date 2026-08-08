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
