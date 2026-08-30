---
paths:
  - "app/Http/Middleware/ResolveTenant.php"
  - "app/Support/Tenant*.php"
  - "app/Support/TrustedTenantHosts.php"
  - "app/Notifications/**"
  - "app/Jobs/**"
  - "app/Console/Commands/*Tenant*.php"
  - "config/app.php"
  - "scripts/server/**"
---

# Dwa modele wdrożenia — kod poprawny w jednym jest błędem w drugim

## Dyskryminator

`config('app.tenant_slug')` — **jedno** wyrażenie, `ResolveTenant.php:36`:

```php
$pinnedSlug = config('app.tenant_slug');
if (filled($pinnedSlug)) { return $this->handlePinnedTenant(...); }
```

| | `TENANT_SLUG` puste | `TENANT_SLUG` ustawione |
|---|---|---|
| model | **współdzielony** (legacy) | **dedykowany stack tenanta** |
| tenant | z Host header, per żądanie | stack **JEST** tenantem |
| `APP_URL` | domena główna, wspólna | własny host tenanta (`apply.sh:714`) |
| komponent ma ustalać tenanta? | **MUSI** | **NIE — to błąd** |

Odpowiednik po stronie danych: kolumna `organizations.singleton`.
Kontrola krzyżowa env kontra baza: `php artisan registro:tenant-provisioned --assert`.

## ZASADA: najpierw model, potem kod

Zanim zaproponujesz zmianę dotykającą tożsamości tenanta, adresów w mailach,
kolejek lub middleware — **ustal, o którym modelu mowa**. Pomiar:

```bash
scripts/architecture-facts.sh          # lokalnie
scripts/architecture-facts.sh --uat    # zdalnie, odczyt, wymaga zgody
```

Repo tego nie powie. `deployment.md` mówi wprost: **plik ≠ rzeczywistość**.
Wnioskowanie o zdalnej instalacji z `.env` maszyny deweloperskiej daje
odpowiedź pewną siebie i błędną (incydent 2026-08-22, niżej).

## Pułapka: dodawanie wielotenantowości do dedykowanego stacku

Kuszące „napraw to, ustalając tenanta z `$notifiable->organizations()->first()`"
jest na dedykowanym stacku **regresją architektoniczną**: wpycha wielotenantową
logikę do komponentu, który ten model celowo trzyma ślepym, i wprowadza gałąź
zdolną wybrać złą organizację po to, by wyliczyć adres, który był już poprawny.

Objaw wyłącznie legacy → naprawą jest **dokończenie migracji**, nie łatka.
Łatka utrwala model, z którego projekt wychodzi.

## Kolejka nie ma kontekstu żądania

`URL::forceRootUrl()` woła `ResolveTenant` — czyli **tylko w żądaniu HTTP**.
Powiadomienie `ShouldQueue` renderuje worker Horizona w osobnym procesie, gdzie
to nigdy nie zaszło, więc `url()`/`route()` spadają na `APP_URL`.

Zweryfikowane: `route('password.reset', [...], true)` bez kontekstu żądania
zwraca host z `APP_URL`, nie subdomenę tenanta.

**Testy tego nie złapią.** `.env.testing` ma `QUEUE_CONNECTION=sync`, więc
powiadomienie renderuje się w żądaniu, gdzie `forceRootUrl` już zadziałał.
Produkcja i dev mają `redis`. To ślepa plamka strukturalna, nie luka w pokryciu —
test pisany na tę klasę błędów musi generować URL **poza** żądaniem.

## Adres dysku `public` to TRZECI, osobny adres

`URL::forceRootUrl()` naprawia `url()` i `route()`. **Nie naprawia `Storage::url()`.**
To trzy niezależne pułapki i każda potrafi zjeść pół dnia:

**(a) Skąd bierze się adres pliku.** `Storage::url()` czyta
`config('filesystems.disks.public.url')`, budowane w `config/filesystems.php:44` jako
`env('APP_URL').'/storage'` — czyli **przy ładowaniu configu**, raz. Na stacku
współdzielonym `APP_URL` to domena GŁÓWNA, więc bez wymuszenia każdy plik każdego tenanta
dostaje adres domeny głównej. Dysk `public` nie ma własnej zmiennej env (`ASSET_URL`
nie występuje w tym repo).

**(b) `forceRootUrl()` tego nie dotyka.** Działa na generatorze URL-i Laravela, a dysk
trzyma własną kopię adresu. Naprawienie stron bez naprawienia plików wygląda jak sukces
do pierwszego uploadu.

**(c) Sama mutacja `config()` bywa cichym no-opem.** `FilesystemManager` cache'uje
zbudowany adapter w `$disks['public']`, a `FilesystemAdapter::url()` czyta `url`
z tablicy przechwyconej **przy konstrukcji**. Jeśli cokolwiek zresolwuje dysk `public`
(albo domyślny — `FILESYSTEM_DISK=public`) zanim wykona się middleware, ustawienie configu
nie zmienia już nic. Stąd `Storage::forgetDisk('public')` w
`ResolveTenant::forceTenantOriginUrls()` jest **load-bearing**, nie ostrożnościowe.

**Objaw, po którym to rozpoznasz:** podgląd pliku w Filamencie (FileUpload/FilePond) wiszący
w nieskończoność na „Pobieranie rozmiaru". To nie limit uploadu ani bug Filamenta — to
`fetch()` cross-origin zablokowany przez CORS, bo `Storage::url()` zwrócił inny host niż
host panelu. Zwykły `<img src>` na storefroncie działa mimo tego samego złego adresu,
bo obrazki nie podlegają CORS — dlatego objaw jest panel-only i mylący.

**Na stacku dedykowanym wady nie ma** — `APP_URL` jest już hostem tenanta, więc wymuszanie
niczego nie zmienia. To jest różnica MODELOZALEŻNA i dlatego mieszka w tym pliku,
a nie w `middleware.md`.

**Poza żądaniem (kolejka, CLI, scheduler) wymuszenie nie działa** i adres spada na `APP_URL`,
czyli domenę główną — patrz sekcja wyżej. Do tego `horizon` i `scheduler` nie montują
wolumenu `storage-app-public` (ClickUp `123k99ct3za`), więc na kolejce plik bywa nie tylko
pod złym adresem, ale i nieosiągalny.

## Odczyt env w kontenerze

`getenv('TENANT_SLUG')` zwraca **`false`**, mimo że `.env` je ustawia
(zweryfikowane empirycznie). `getenv('APP_URL')` działa. Nigdy nie rozstrzygaj
modelu przez `getenv` — zawsze `config('app.tenant_slug')`, to samo wyrażenie,
na którym rozgałęzia się `ResolveTenant`.

## Konta: co provisioning zakłada, a czego nie

| ścieżka | zakłada |
|---|---|
| `deploy-init.sh:544` → `registro:create-owner` | super-admina instalacji |
| `scripts/server/apply.sh` → `registro:tenant-provision` | organizację + właściciela (rola `admin`) |

`apply.sh` **nigdy** nie woła `create-owner`. Na świeżym stacku tenanta nie ma
super-admina, więc „wejdziemy i zresetujemy właścicielowi hasło z panelu" jest
nieprawdą, dopóki ktoś go tam ręcznie nie założy.

Ścieżka operatorska przy zablokowanym właścicielu:
`php artisan registro:password-setup-link <email> --force` — link idzie na
stdout, żeby zepsuta poczta nie odcięła dostępu.

## Incydent 2026-08-22 — dlaczego ten plik istnieje

**Problem:** na pytanie „co się stanie, gdy admin tenanta zapomni hasła"
odpowiedziano, że link resetu prowadzi na złą domenę — jako wadę produkcji.

**Przyczyna:** prześledzono kod i wyciągnięto wniosek o produkcji z `APP_URL`
maszyny deweloperskiej, nie ustaliwszy najpierw modelu wdrożenia. Na dedykowanym
stacku `APP_URL` jest domeną tenanta i wada nie istnieje.

**Rozwiązanie:** wada dotyczy wyłącznie kombinacji *stack współdzielony +
tenant na subdomenie*. Zaproponowana naprawa przez `TenantUrl` była wycelowana
w zły model i zostałaby regresją.

**Zapobieganie:** `scripts/architecture-facts.sh` + wstrzykiwanie faktów przez
`UserPromptSubmit`; brak pomiaru zdalnego jest raportowany jako **NIEZNANY**,
nigdy dopowiadany.

## Co przetrwało oba modele

`ResetPasswordController:28` ma `$redirectTo = '/home'`, a trasa o nazwie `home`
to `/`. `/home` → **404**, niezależnie od modelu, domeny i kolejki.
