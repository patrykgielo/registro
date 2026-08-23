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
