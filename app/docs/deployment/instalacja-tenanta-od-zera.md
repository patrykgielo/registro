# Instalacja tenanta od zera — ścieżka operatora

**Dla kogo:** dla Ciebie, o drugiej w nocy, kiedy coś nie działa. Nie dla programisty
szukającego architektury — na to są osobne dokumenty, linki na końcu.

**Zasada tego dokumentu:** każda komenda ma jedną linię wyjaśnienia CO ROBI i opis tego, co
powinieneś zobaczyć. Jeśli zobaczysz coś innego — zatrzymaj się, nie idź dalej.

---

## ⚠️ Przeczytaj to raz, zanim cokolwiek uruchomisz

**Części 1 i 2 tego dokumentu nigdy nie były wykonane na serwerze.** Kod istnieje, jest
zrecenzowany i przetestowany lokalnie, ale brzeg nigdy nie stał, a żaden tenant nigdy nie dostał
własnego stacka na prawdziwej maszynie.

Wzorzec z tego projektu jest jednoznaczny: **każde pierwsze uruchomienie czegoś znajdowało pięć
do sześciu realnych błędów.** Nie dlatego, że kod jest zły — dlatego, że rzeczywistość ma więcej
wymiarów niż test.

Traktuj pierwszy przebieg jako **znajdowanie błędów, nie jako ceremonię**. Miej ten dokument
otwarty i poprawiaj go w locie. Dokument staje się prawdziwy przez uruchomienie, nie przez
napisanie.

**Stan zweryfikowany:** 2026-08-09. Gdy będziesz to czytał później — sprawdź, czy data nie jest
podejrzanie stara.

---

## Słownik — trzy pojęcia, które łatwo pomylić

| pojęcie | co to jest |
|---|---|
| **stack legacy** | Dziś działający serwer. Jeden zestaw kontenerów `registro-*` obsługujący wszystkich. Tu żyje tenant `budowlana`. |
| **stack tenanta** | Osobny komplet kontenerów dla JEDNEGO klienta. Własna baza, własny Redis, własne kontenery. To jest to, co instalujemy. |
| **brzeg (edge)** | Jeden nginx, który jako jedyny trzyma porty 80 i 443, i rozdziela ruch do stacków tenantów po nazwie hosta. |

**`srv1342834.hstgr.cloud` to nazwa serwera do SSH. `registrolabs.com` to domena aplikacji.**
To dwie różne rzeczy i nie wolno ich mylić.

---

# CZĘŚĆ 0 — Przygotowanie serwera (raz w życiu)

Serwer dziś tego nie ma. Sprawdź, zanim zaczniesz:

> Pokazuje, czego brakuje. Wszystko na `BRAK` musisz dołożyć poniżej.

```bash
ssh root@srv1342834.hstgr.cloud '
for x in /opt/registro/apply.sh /opt/registro/tenant-check.sh /opt/stacks; do
  printf "%-32s %s\n" "$x" "$([ -e $x ] && echo JEST || echo BRAK)"
done
printf "%-32s %s\n" restic "$(command -v restic >/dev/null && echo JEST || echo BRAK)"'
```

### 0.1 — Zainstaluj restic

> Restic robi backupy. Bez niego `apply` nadal zadziała, ale ostrzeże i zakończy się statusem
> `DEGRADED` — czyli „wdrożone, ale bez kopii zapasowej".

```bash
ssh root@srv1342834.hstgr.cloud 'apt-get update -qq && apt-get install -y restic dnsutils'
```

Powinieneś zobaczyć instalację bez błędów. `dnsutils` daje `dig`, którego używają warunki wstępne.

### 0.2 — Utwórz katalog na stacki tenantów

> Każdy tenant dostanie tu swój podkatalog z kodem, `.env` i stanem.

```bash
ssh root@srv1342834.hstgr.cloud 'install -d -m 755 -o deploy -g deploy /opt/stacks && ls -ld /opt/stacks'
```

Powinieneś zobaczyć katalog należący do `deploy`.

### 0.3 — Włącz linger dla użytkownika deploy

> `apply` odczepia się od sesji SSH przez `systemd-run --user`. Bez lingera proces zginie
> w momencie, gdy zamkniesz terminal — czyli dokładnie wtedy, gdy najbardziej nie chcesz.

```bash
ssh root@srv1342834.hstgr.cloud 'loginctl enable-linger deploy && loginctl show-user deploy | grep Linger'
```

Powinieneś zobaczyć `Linger=yes`.

### 0.4 — Zainstaluj skrypty operatora

> Kopiuje trzy skrypty z repo na serwer, jako własność roota, żeby nikt inny ich nie podmienił.
> Uruchom to z katalogu repo na swojej maszynie.

```bash
cd /var/www/projects/registro/app
for f in apply.sh tenant-check.sh tenant-backup.sh; do
  scp scripts/server/$f root@srv1342834.hstgr.cloud:/tmp/$f
  ssh root@srv1342834.hstgr.cloud "install -m 755 -o root -g root /tmp/$f /opt/registro/$f && rm /tmp/$f"
done
ssh root@srv1342834.hstgr.cloud 'ls -l /opt/registro/'
```

Powinieneś zobaczyć trzy pliki `-rwxr-xr-x root root`.

### 0.5 — Sprawdź logowanie do rejestru obrazów

> `apply` pobiera obraz aplikacji z GHCR. Bez zalogowania pobranie padnie w połowie instalacji.

> Użyj **istniejącego** tagu, nie `latest` — tag `latest` powstaje dopiero przy wydaniu przez
> GitHub Actions, a ten workflow nigdy nie był uruchomiony. Pobranie `latest` padnie z
> `manifest unknown` i wyślesz się na fałszywy trop.

```bash
ssh deploy@srv1342834.hstgr.cloud 'docker pull ghcr.io/patrykgielo/registro:v0.13.0-rc9 >/dev/null && echo "dostep do rejestru OK"'
```

Rozróżniaj dwa błędy: `unauthorized` znaczy brak logowania — zrób `docker login ghcr.io` jako
`deploy` tokenem `read:packages`. `manifest unknown` znaczy, że taki tag nie istnieje — sprawdź
listę dostępnych wersji w GitHub Packages.

---

# CZĘŚĆ 1 — Postawienie brzegu (raz w życiu)

Brzeg przejmuje porty 80 i 443 od dotychczasowego nginxa. **To jedyny moment w całej instrukcji,
w którym istniejąca strona może przestać działać.** Dlatego ma własną, numerowaną procedurę
z odwrotem na każdym kroku.

**Zanim zaczniesz:** pełna procedura przełączenia jest w `edge-stack.md`, sekcja „Cutover".
Ta tutaj jest jej skrótem. Przy pierwszym uruchomieniu czytaj obie.

### 1.0 — Wygeneruj config brzegu z szablonu (BEZ TEGO KROK 1.1 PADNIE)

> Plik `edge-tls.local.conf` **nie istnieje w repo** — jest generowany, bo zawiera nazwę katalogu
> certyfikatu, która jest inna na każdej instalacji. Pierwsza komenda odczytuje tę nazwę z `.env`,
> druga podstawia ją w szablonie.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /var/www/registro && \
  CERT=$(grep -m1 "^CERT_DIR=" .env | cut -d= -f2-) && echo "katalog certyfikatu: $CERT" && \
  sed "s|CERT_DOMAIN|$CERT|g" docker/nginx/edge/edge-tls.conf > docker/nginx/edge/edge-tls.local.conf && \
  echo "pozostalo placeholderow: $(grep -c CERT_DOMAIN docker/nginx/edge/edge-tls.local.conf || true)"'
```

Powinieneś zobaczyć nazwę katalogu (dziś `registrolabs.com`) i `pozostalo placeholderow: 0`.
Cokolwiek innego niż `0` — nie idź dalej.

*(`grep -c` zwraca kod błędu przy zerze trafień, dlatego jest owinięty w `|| true` — inaczej cała
komenda kończyłaby się niepowodzeniem właśnie wtedy, gdy wszystko poszło dobrze. Pamiętaj o tym,
jeśli kiedyś przekleisz tę linię do skryptu.)*

### 1.1 — Sprawdź konfigurację, NIE zajmując portu

> Parsuje config brzegu w kontenerze jednorazowym. `docker compose run` bez `--service-ports`
> **nie publikuje portów**, więc nie zderzy się z działającą stroną. To zostało sprawdzone
> przez `docker inspect`, nie wzięte z dokumentacji.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /var/www/registro && \
  EDGE_NGINX_CONF=edge-tls.local.conf docker compose -f docker-compose.edge.yml \
  run --rm --entrypoint sh edge-nginx -c "nginx -t"'
```

Musisz zobaczyć `test is successful`. **Jeśli nie — STOP.** Nie zatrzymuj starego nginxa.

### 1.2 — Zatrzymaj publikowanie portów przez stary stack

> Dwa kontenery nie mogą trzymać tego samego portu. Stary musi puścić, zanim brzeg weźmie.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /var/www/registro && docker compose -f docker-compose.prod.yml stop nginx'
```

**Od tego momentu strona nie działa.** Następny krok ma ją przywrócić.

### 1.3 — Postaw brzeg

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /var/www/registro && \
  EDGE_NGINX_CONF=edge-tls.local.conf docker compose -f docker-compose.edge.yml up -d edge-nginx'
```

### 1.4 — Sprawdź z zewnątrz, nie z komunikatu

> Komunikat o sukcesie potrafi kłamać. Sprawdzaj skutek, nie kod wyjścia.

```bash
curl -s -o /dev/null -w "HTTP %{http_code} tls=%{ssl_verify_result}\n" https://registrolabs.com/
```

Musisz zobaczyć `HTTP 200 tls=0`.

### 1.5 — ODWRÓT, jeśli 1.3 lub 1.4 zawiodło

> Zatrzymuje brzeg i przywraca stary nginx. Strona wraca w kilka sekund.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /var/www/registro && \
  docker compose -f docker-compose.edge.yml stop edge-nginx && \
  docker compose -f docker-compose.prod.yml up -d nginx'
```

---

# CZĘŚĆ 2 — Nowy tenant (raz na klienta)

Tu jest cała nagroda za poprzednie części: **instalacja klienta to jedna komenda.**

`apply` sam robi wszystko po kolei: warunki wstępne, pobranie kodu, wygenerowanie sekretów,
przydzielenie portów i podsieci, **utworzenie sieci dockerowej**, migracje, postawienie sześciu
kontenerów, seedy, podpięcie do brzegu, sprawdzenie izolacji, backup i dopiero na końcu zapisanie
wersji.

### 2.1 — Wybierz slug

Slug to nazwa techniczna klienta, widoczna w adresie: `<slug>.<domena tej maszyny>` —
dziś `<slug>.registrolabs.com`, bo to jest domena tej maszyny (patrz niżej).

**Slug trafia do publicznych logów Certificate Transparency.** Każdy może je przeglądać. Jeśli
klient nie chce, żeby świat wiedział, że z Ciebie korzysta — użyj neutralnego sluga.

Dozwolone: małe litery, cyfry, myślnik. Bez polskich znaków, bez kropek.

### 2.2 — Uruchom instalację

> Jedna komenda instaluje całego klienta. `--foreground` sprawia, że **widzisz to na żywo** —
> a o to Ci chodzi przy pierwszym razie. Bez tej flagi proces odczepia się od terminala.

> **Bez trzeciego argumentu (`[hosts]`)** — celowo, poniżej. Domena, na której odpowie klient,
> to **właściwość tej maszyny**, nie coś, co operator wpisuje przy każdym wywołaniu. `apply.sh`
> czyta ją z `APP_DOMAIN` w `.env` legacy checkoutu (tej samej wartości, którą
> `docker-compose.prod.yml` już wymaga do wystartowania — nic nowego do skonfigurowania na tej
> maszynie) i buduje `<slug>.<APP_DOMAIN>` sam. Podanie `[hosts]` ręcznie nadal działa i
> **nadpisuje** ten domyślny wybór całkowicie — to jest droga dla własnej domeny klienta
> (Część 3+, jeszcze nieopisana), nie coś do rutynowego używania tutaj. Jeśli `APP_DOMAIN` nie
> jest ustawione w `.env`, komenda odmawia z komunikatem, co ustawić — nie zgaduje.

```bash
ssh -t deploy@srv1342834.hstgr.cloud '/opt/registro/apply.sh nazwaklienta v0.13.0-rc9 \
  --name="Pełna Nazwa Sp. z o.o." \
  --owner-email=wlasciciel@example.com \
  --owner-name="Jan Kowalski" \
  --industry=equipment_rental \
  --foreground'
```

**Co zobaczysz:** kolejne kroki z etykietami, potem sześć kontenerów wstających jeden po drugim.
To jest ten moment, o który pytałeś — widać, jak powstaje cały stack klienta.

**Na końcu musi paść `OK`.** Kody wyjścia:

| kod | znaczenie | co robić |
|---|---|---|
| `0` | sukces | nic |
| `1` | zły argument | popraw komendę |
| `2` | warunki wstępne | DNS nie rozwiązuje albo brak miejsca na dysku |
| `3` | instalacja padła | czytaj log, patrz Część 5 |
| `4` | już działa inna instalacja tego klienta | poczekaj |
| `5` | **DEGRADED** — aplikacja działa, ale backup padł | klient działa, napraw backup |

### 2.3 — Odczytaj status, jeśli uruchomiłeś bez `--foreground`

> Proces odczepiony od SSH gubi kod wyjścia. Dlatego pisze status do pliku. **Nie zgaduj
> z dziennika systemowego** — czytaj ten plik.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cat /opt/stacks/.state/nazwaklienta/apply-status'
```

Zobaczysz `RUNNING`, `OK`, `FAILED` albo `DEGRADED`. **`RUNNING` po zakończeniu oznacza, że proces
został zabity** — nigdy nie oznacza sukcesu.

### 2.4 — Sprawdź, że klient odpowiada

```bash
curl -s -o /dev/null -w "HTTP %{http_code} tls=%{ssl_verify_result}\n" https://nazwaklienta.registrolabs.com/
```

Spodziewaj się **błędu certyfikatu**. To jest oczekiwane i wymaga Twojego działania — patrz 2.5.

### 2.5 — Poczekaj na crona uzgadniającego nazwy

**Ten krok jest teraz automatyczny — dawniej wymagał ręcznej interwencji, patrz niżej.**

`sync-certificate.sh` enumeruje dziś zarówno starą, współdzieloną bazę (o ile jej kontener w ogóle
działa na tej maszynie — na maszynie bez stacka legacy w ogóle, np. świeże PreProd, to źródło
legalnie wnosi zero nazw, patrz Faza 2 planu dwóch maszyn), JAK i każdy dedykowany stack pod
`/opt/stacks/*/` (czyta `TENANT_HOSTS` z pliku `.env` tego stacka NA DYSKU, nie z żywego kontenera —
dokładnie tę samą listę, którą `apply.sh` już tam zapisał; kontener stacka może być zatrzymany, to
już nie ma znaczenia). Nowy klient dostanie się na certyfikat przy najbliższym przebiegu crona
(`/etc/cron.d/registro-certificate`, co 15 minut) — bez żadnego ręcznego kroku. Do tego czasu `curl`
z kroku 2.4 pokaże błąd certyfikatu — to oczekiwane, poczekaj do 15 minut i powtórz sprawdzenie;
oczekuj `HTTP 200 tls=0`.

**Jeśli po 15+ minutach certyfikat WCIĄŻ nie pokrywa nowego klienta** — to nie jest już "znana
luka", tylko awaria: sprawdź log crona (`/var/log/registro-certificate.log` na serwerze). Skrypt
teraz **przerywa cały przebieg** (i zostawia certyfikat bez zmian), jeśli:
- stara, współdzielona baza MA działający kontener, ale zapytanie o jej nazwy zawiodło (różne od
  "kontener po prostu nie działa" — to jest legalne i wnosi zero nazw, patrz wyżej), lub
- którykolwiek dedykowany stack istnieje (ma plik `docker-compose.prod.yml`), ale jego `.env` jest
  nieczytelny albo nie ma w nim `TENANT_HOSTS`, lub
- żadne z powyższych źródeł (stara baza, dedykowane stacki, `www`) nie wniosło ŻADNEJ nazwy —

więc brak aktualizacji zwykle oznacza, że TEN klient (albo inny stack na tym samym serwerze) jest w
takim stanie, nie że mechanizm enumeracji znów widzi tylko starą bazę.

<details>
<summary>Historia: dawna procedura ręczna (dla kontekstu, już niepotrzebna)</summary>

Przed naprawą `sync-certificate.sh` pytał wyłącznie o bazę starego, współdzielonego stacka —
klient na własnym stacku nigdy tam nie trafiał, a skrypt wystawiał certyfikat na dokładnie tę
listę, więc każda ręcznie dodana nazwa znikała przy najbliższym przebiegu. Wymagało to
wstrzymania crona, ręcznego `certbot certonly --expand` z pełną listą nazw i ręcznego reloadu
edge'a. Ta procedura jest teraz zbędna — zostawiona tu wyłącznie jako historia problemu, nie jako
instrukcja do wykonania.

</details>

---

# CZĘŚĆ 3 — Kontrola stanu

### 3.1 — Audyt

> Sprawdza rzeczy, które przy projektowaniu tego systemu realnie się rozjeżdżały: liczbę
> organizacji w bazie, osierocone kontenery, czy nginx nie jest wystawiony szerzej niż na
> loopback, czy `TRUSTED_PROXIES_CIDR` nie jest gwiazdką i czy zgadza się z podsiecią.
>
> **Milczy, gdy jest czysto.** Brak wyjścia to dobra wiadomość.

```bash
ssh deploy@srv1342834.hstgr.cloud '/opt/registro/tenant-check.sh; echo "kod: $?"'
```

`kod: 0` i cisza = wszystko w porządku.

### 3.2 — Co faktycznie działa

```bash
ssh deploy@srv1342834.hstgr.cloud 'docker ps --format "{{.Names}}\t{{.Status}}" | sort'
```

---

# CZĘŚĆ 4 — Pierwsze logowanie i wprowadzenie sprzętu

### 4.1 — Link do ustawienia hasła

`apply` **wypisuje link na ekran** przy tworzeniu klienta. Nie idzie mailem — poczta jest celowo
poza krytyczną ścieżką instalacji, żeby awaria SMTP nie wywracała wdrożenia.

Link jest **ważny `User::PASSWORD_SETUP_TTL_HOURS` godzin (dziś: 24)**. Jeśli przepadnie:

> Generuje nowy token, drukuje nowy link na ekran i (domyślnie) wysyła go mailem tym samym
> mechanizmem co przycisk „Wyślij email z hasłem" w panelu admina. Odmawia, jeśli konto ma już
> ustawione hasło — bez tego dopisek `--force` mógłby posłużyć do przejęcia działającego konta
> (nowy link pozwala nadpisać hasło bez znajomości starego).

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /opt/stacks/nazwaklienta && \
  docker compose -f docker-compose.prod.yml exec -T app php artisan registro:password-setup-link wlasciciel@example.com'
```

Zobaczysz `User`, `Organization(s)`, `Valid for`, `Notification` i na końcu sam link. Jeśli poczta
jest tymczasowo martwa, dodaj `--no-email` — link i tak zostanie wydrukowany, wyślesz go klientowi
ręcznie. Zweryfikowane 2026-08-09 na lokalnym stacku deweloperskim: token zapisuje się w bazie,
link renderuje formularz ustawienia hasła (`SetPasswordControllerTest` pokrywa to end-to-end), guard
przy koncie z już ustawionym hasłem odmawia bez `--force` i przepuszcza z nim. Komenda ma testy w
`tests/Feature/Console/ResendPasswordSetupLinkCommandTest.php`.

### 4.2 — Wprowadzenie sprzętu

Zalogowany właściciel wchodzi w panel: `https://nazwaklienta.registrolabs.com/admin`

Sprzęt to **Usługi** z typem „wypożyczenie". Dla każdej pozycji potrzebujesz:

- nazwa i opis
- **ilość w magazynie** — to jest to, co blokuje podwójną rezerwację
- cena za dobę, opcjonalnie za godzinę i za tydzień
- **kaucja** — nie wlicza się do kwoty zamówienia, jest zwrotna
- kategoria

### 4.3 — Przejdź pełną ścieżkę, zanim oddasz klientowi

Rezerwacja → płatność → potwierdzenie przez admina → wydanie → zwrot.

**Co wiadomo, że nie działa** (stan na 2026-08-09, zmapowane, nie zgadywane):

- **nie ma żadnego PDF dla klienta** — ani protokołu wydania, ani zwrotu, ani umowy, ani faktury
- **nie ma maila przy „Wydano klientowi" ani przy „Sprzęt zwrócony"**
- **nie ma przypomnienia o zbliżającym się terminie zwrotu**
- pole „zakończono" nigdy się nie zapisuje
- Przelewy24 nie były testowane prawdziwą transakcją

---

# CZĘŚĆ 5 — Kiedy coś nie działa

## Trzy pułapki, które już nas kosztowały czas

### „Zmieniłem `.env`, a aplikacja nadal widzi stare wartości"

**To nie jest błąd, to działanie Dockera.** Compose wstawia zmienne środowiskowe **przy tworzeniu
kontenera**, a zmienna systemowa kontenera bije plik `.env` w kolejności priorytetów Laravela.
`config:cache` tego nie naprawi.

> Odtwarza kontenery z nowymi wartościami. **Drugi plik `-f` jest obowiązkowy** — to on podłącza
> nginxa klienta do sieci brzegowej. Bez niego kontener wstanie, ale wypadnie z tej sieci i brzeg
> przestanie do niego trafiać, po cichu, aż do następnego `apply`.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /opt/stacks/nazwaklienta && \
  docker compose -f docker-compose.prod.yml -f docker-compose.tenant-network.override.yml up -d'
```

### „Przeładowałem nginx, a on serwuje stary config"

Config nginxa jest montowany jako **pojedynczy plik**. Kontener trzyma jego i-node, więc po
podmianie pliku widzi starą wersję — i posłusznie ją przeładowuje. `nginx -t` przechodzi, reload
zgłasza sukces, strona odpowiada. Starą treścią.

> Wymusza odtworzenie kontenera, żeby przejął nowy plik.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /var/www/registro && docker compose -f docker-compose.prod.yml up -d --force-recreate nginx'
```

**Zasada z obu tych przypadków, najważniejsza w całym dokumencie:**
**po zmianie czegokolwiek, co konsumuje kontener — sprawdzaj SKUTEK z zewnątrz, a nie kod wyjścia
komendy.** Certyfikat sprawdzaj `openssl s_client`, stronę `curl`, domenę `dig`.

### „Backup blokuje każdą kolejną instalację"

Przerwany backup potrafi zostawić zakleszczony zamek restica.

> Zdejmuje zamek. Skrypt sam wykryje ten przypadek i podpowie tę komendę w logu.

```bash
ssh deploy@srv1342834.hstgr.cloud 'restic unlock'
```

## Gdzie szukać logów

```bash
ssh deploy@srv1342834.hstgr.cloud 'ls -t /var/log/registro-*.log && tail -50 /var/log/registro-apply.log'
```

---

# CZĘŚĆ 6 — Codzienna eksploatacja

### Wydanie nowej wersji u klienta

> Ta sama komenda co instalacja. `apply` jest reconcilerem — nie ma osobnej ścieżki „aktualizuj",
> bo ścieżka używana raz na klienta gnije niezauważona.

```bash
ssh deploy@srv1342834.hstgr.cloud '/opt/registro/apply.sh nazwaklienta v0.14.0'
```

### Backup ręcznie

```bash
ssh deploy@srv1342834.hstgr.cloud '/opt/registro/tenant-backup.sh nazwaklienta'
```

**Ograniczenie, o którym musisz wiedzieć:** repozytorium backupu i jego hasło leżą **na tym samym
serwerze, który backupujemy**. To jest redundancja dyskowa, **nie disaster recovery**. Jeśli
serwer przepadnie, przepadnie z nim kopia. Domknięcie tego wymaga wskazania zdalnego magazynu
i wyniesienia hasła poza maszynę.

---

# CZĘŚĆ 7 — Usunięcie klienta (offboarding)

**To jest jedyna część tego dokumentu, w której końcowy krok jest nieodwracalny i niszczy dane.**
Przeczytaj całość, zanim zaczniesz — kolejność ma znaczenie, a odwrócenie kolejności realnie psuje
odnawianie certyfikatu dla WSZYSTKICH innych klientów na serwerze (wyjaśnione w kroku 7.5).

## 7.0 — Co już istnieje w aplikacji, a czego nie ma na poziomie operatora

Aplikacja ma pełną maszynę stanów offboardingu (`OrganizationLifecycleState`,
`StartOrganizationOffboarding`, `CancelInFlightObligationsJob`, `organizations:finalize-closing`,
`organizations:purge`) — to nie jest coś, co trzeba dopiero zbudować. **Nie istnieje** natomiast
żadna komenda „zdejmij tego klienta z serwera" — ta część dokumentu składa istniejące klocki
aplikacyjne z realnymi krokami na kontenerach/wolumenach/sieci/certyfikacie, bo nic tego dotąd nie
robiło razem.

**Retencja prawna, zanim cokolwiek innego:** faktury i płatności (`orders`, `payments`,
`tenant_payments`, `rentals`) muszą przetrwać **6 lat** (Art. 112 ustawy o VAT,
`config('retention.legal_records_years')`). `organizations:purge` anonimizuje dane osobowe na tych
wierszach, ale **celowo zostawia same wiersze w bazie** — nigdy ich nie usuwa. Usunięcie całego
wolumenu bazy danych tego stacka (krok 7.6) zniszczyłoby je fizycznie. Dlatego krok 7.4 (ostatni
backup) jest obowiązkowy i musi się udać, zanim wykonasz krok 7.6 — backup jest jedynym miejscem,
w którym te dane przeżyją usunięcie stacka.

## 7.1 — Zamknij na poziomie aplikacji (Closing)

> Dedykowany stack nie ma domyślnie żadnego konta `super-admin` — właściciel klienta dostaje tylko
> rolę `admin` (patrz `registro:tenant-provision`). `StartOrganizationOffboarding` wymaga aktora
> `super-admin` (log audytowy + autoryzacja), więc najpierw zapewnij sobie taki dostęp na TYM
> stacku — ta sama komenda, która tworzy właściciela całej instalacji Registro:

> Generuje losowe hasło lokalnie w sesji SSH (nigdy nie trafia do tego dokumentu ani do żadnego
> logu) i drukuje je na koniec — zapisz je, jeśli planujesz kiedyś zalogować się tym kontem przez
> `/platform` tego stacka. Do samej autoryzacji akcji offboardingu poniżej nie jest potrzebne
> jeszcze raz — token/hasło żyje tylko w tej jednej sesji.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /opt/stacks/nazwaklienta && \
  OPS_PASSWORD="$(openssl rand -base64 24)" && \
  docker compose -f docker-compose.prod.yml exec -T app php artisan registro:create-owner \
    --first-name=Ops --last-name=Registro --email=ops@registrolabs.com \
    --password="$OPS_PASSWORD" --force && \
  echo "haslo konta ops@registrolabs.com na tym stacku: $OPS_PASSWORD"'
```

Idempotentna — jeśli to konto już istnieje na tym stacku (np. z poprzedniej operacji), `--force`
nadpisuje mu hasło bez pytania. Zachowaj wydrukowane hasło, jeśli chcesz później zalogować się tym
kontem przez `/platform`; jeśli nie — możesz je od razu zapomnieć, komenda niżej go już nie
potrzebuje.

> Wywołuje prawdziwą, aplikacyjną ścieżkę offboardingu — nie ręczne grzebanie w kolumnach. Anuluje
> rezerwacje/zamówienia w toku (z powiadomieniem klientów), przechodzi org w stan `Closing`,
> kolejkuje eksport danych właściciela (RODO art. 28 ust. 3 lit. g) i zapisuje wpis w
> `organization_lifecycle_log`.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /opt/stacks/nazwaklienta && \
  docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="
    \$org = App\Models\Organization::firstOrFail();
    \$actor = App\Models\User::where(\"email\", \"ops@registrolabs.com\")->firstOrFail();
    app(App\Actions\Offboarding\StartOrganizationOffboarding::class)->execute(\$org, \$actor);
    echo \$org->fresh()->lifecycle_state->value.PHP_EOL;
  "'
```

Musisz zobaczyć `closing`. Od tego momentu strona publiczna klienta jest wyłączona (`allowsPublicSite()
= false`) i nie da się złożyć nowej rezerwacji — ale konto wciąż istnieje i jest **odwracalne** przez
kolejne 14 dni (`closing_grace_days`, `config/retention.php`) przez akcję „Reaktywuj" w
`/platform` tego stacka (lub `$org->lifecycle_state = OrganizationLifecycleState::Active; $org->save();`
w tym samym stylu tinkera, jeśli operator nie ma dostępu do panelu).

## 7.2 — Poczekaj na finalizację (Closed) i anonimizację (purge)

Każdy dedykowany stack ma własny kontener `scheduler` (ten sam `docker-compose.prod.yml`), więc
poniższe dzieje się **automatycznie**, bez dalszej interwencji:

| Kiedy | Co | Komenda (harmonogram) |
|---|---|---|
| `closing_initiated_at` + 14 dni | `Closing → Closed`, ustawia `purge_after` | `organizations:finalize-closing`, dziennie 02:30 |
| `purge_after` (Closed + 30 dni) | anonimizacja PII, ephemeral dane usunięte, org soft-deleted | `organizations:purge`, dziennie 03:00 |

Sprawdź postęp bez dotykania niczego:

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /opt/stacks/nazwaklienta && \
  docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="
    echo App\Models\Organization::withTrashed()->firstOrFail()->lifecycle_state->value.PHP_EOL;
  "'
```

**Nie ma flagi „pomiń okres karencji" dla pojedynczej organizacji.** 14 i 30 dni to świadome okna
(czas na odwołanie/reklamację), zakodowane w `config/retention.php`, nie per-klient. Jeśli presja
biznesowa każe zejść ze stackiem szybciej niż to — patrz 7.3: backup i tak zabezpiecza dane prawne,
więc fizyczne zdjęcie stacka (7.6) MOŻE nastąpić przed `purge`, kosztem tego, że anonimizacja PII
nigdy się dla tej organizacji nie wykona (jej wiersze przetrwają w backupie z danymi osobowymi wciąż
czytelnymi). To świadomy kompromis, nie coś do zignorowania — zapisz decyzję i datę.

## 7.3 — Ostatni backup, zweryfikowany zanim ruszysz dalej

> Ta sama komenda co w Części 6 — uruchom ją jeszcze raz, świadomie, jako OSTATNI zrzut przed
> usunięciem stacka. Wymaga działającego kontenera `mysql` — dlatego to musi się stać PRZED krokiem
> 7.5/7.6, nigdy po.

```bash
ssh deploy@srv1342834.hstgr.cloud '/opt/registro/tenant-backup.sh nazwaklienta'
```

> Nie ufaj samemu kodowi wyjścia — sprawdź, że snapshot faktycznie istnieje i ma sensowny rozmiar
> (kilka–kilkanaście KB dla małego klienta, nie kilkadziesiąt bajtów).

```bash
ssh deploy@srv1342834.hstgr.cloud '
  export RESTIC_REPOSITORY=/opt/registro/tenant-backups/nazwaklienta/repo
  export RESTIC_PASSWORD_FILE=/opt/registro/tenant-backups/nazwaklienta/password
  restic snapshots --host tenant-nazwaklienta --latest 1'
```

**Jeśli ten krok się nie powiedzie lub snapshot wygląda podejrzanie mały — STOP.** Nie idź do 7.5/7.6
bez potwierdzonego, świeżego backupu. To jedyna kopia danych prawnych, które za chwilę znikną z
żywej bazy.

## 7.4 — Odłącz od brzegu (edge)

> Brzeg (`docker-compose.edge.yml`) generuje swój plik podpięć **automatycznie z zestawu plików
> `tenants.d/*.conf` faktycznie obecnych na dysku** — dokładnie ten sam mechanizm, którego używa
> `apply.sh`'s krok `edge-sync` przy DODAWANIU klienta (patrz `edge-stack.md`). Usunięcie klienta to
> lustrzane odbicie: usuń jego plik vhost, odtwórz plik podpięć z tego, co zostało, przeładuj.
> Uruchom z checkoutu **legacy**, nie z katalogu tego klienta — brzeg nie jest duplikowany per
> tenant.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /var/www/registro && \
  rm -f docker/nginx/edge/tenants.d/nazwaklienta.conf && \
  { echo "networks:"; \
    for f in docker/nginx/edge/tenants.d/*.conf; do \
      [ -f "$f" ] || continue; s="$(basename "$f" .conf)"; \
      echo "  tenant-${s}-edge:"; echo "    external: true"; \
    done; \
    echo "services:"; echo "  edge-nginx:"; echo "    networks:"; \
    for f in docker/nginx/edge/tenants.d/*.conf; do \
      [ -f "$f" ] || continue; s="$(basename "$f" .conf)"; \
      echo "      - tenant-${s}-edge"; \
    done; \
  } > docker-compose.edge.tenants.override.yml.tmp && \
  mv -f docker-compose.edge.tenants.override.yml.tmp docker-compose.edge.tenants.override.yml && \
  docker compose -f docker-compose.edge.yml -f docker-compose.edge.tenants.override.yml up -d edge-nginx'
```

*(To dosłownie ta sama pętla co `apply.sh`'s krok `edge-sync`, łącznie z zapisem do `.tmp` +
`mv -f` zamiast bezpośrednio do celu — `apply.sh:978-979` robi to samo, żeby przerwany zapis
(pełny dysk, zerwane SSH) nie zostawił edge'a czytającego na wpół zapisany YAML. Jeśli ten krok
się kiedyś zmieni w skrypcie, zdiffuj go z tym blokiem.)*

```bash
curl -skS -o /dev/null -w "HTTP %{http_code}\n" https://nazwaklienta.registrolabs.com/; echo "curl exit: $?"
```

**Oczekuj `HTTP 000`, linii `curl: (52) Empty reply from server` i `curl exit: 52`.** To jest poprawny
wynik, nie błąd sprawdzenia — nginx `444` zamyka połączenie bez wysłania nawet linii statusu, więc
`curl` nie ma czego zaraportować jako prawdziwy kod HTTP i pokazuje `000`. `-S` (obok `-s`) jest
tu celowe: bez niego `curl` nadal wypisze `HTTP 000` (to linia z `-w`, nie komunikat błędu, `-s` jej
nie tłumi), ale połknie komunikat `(52) Empty reply from server`, który jest tu jedynym dowodem
odróżniającym "zerwane połączenie" od "serwer naprawdę odpowiedział kodem 000" (nie istnieje, ale
lepiej nie zgadywać). Zweryfikowane lokalnie na jednorazowym nginx zwracającym gołe `444` — dokładnie
to polecenie, dokładnie ten wynik. Domena nie pasuje już do żadnego vhosta brzegu i trafia na
`default_server`. Opcjonalnie posprzątaj też stronę serwisową:
`rm -rf docker/nginx/edge/tenant-pages/nazwaklienta`.

## 7.5 — Kolejność, którą łatwo pomylić (i dlaczego to psuje WSZYSTKICH klientów)

`sync-certificate.sh` (co 15 minut, patrz krok 2.5) enumeruje `/opt/stacks/*` — dla każdego katalogu
**z obecnym plikiem `docker-compose.prod.yml`** czyta `TENANT_HOSTS` z jego pliku `.env` na dysku
(od Fazy 2 planu dwóch maszyn — wcześniej odpytywał żywy kontener `app`, co zamrażało cały przebieg
za każdym razem, gdy projekt na UAT stał zatrzymany między sesjami). **Zatrzymany kontener już nie
jest problemem** — `.env` jest czytelny niezależnie od tego, czy kontener działa. Problemem
pozostaje katalog, który MA plik `docker-compose.prod.yml`, ale którego `.env` zniknął, jest
nieczytelny albo nie ma w nim `TENANT_HOSTS` — wtedy skrypt nadal **przerywa CAŁY przebieg** i **nie
dotyka certyfikatu wcale**, dla żadnego klienta, dopóki sytuacja się nie wyjaśni (zamierzony
fail-safe, patrz krok 2.5).

**Zasada:** krok 7.6 (zatrzymanie kontenerów + usunięcie katalogu) rób jako jedną, nieprzerywaną
sekwencję poleceń — nie zostawiaj przerwy między „kontenery stoją" a „katalog zniknął". To wciąż
dobra higiena (katalog z martwym `.env` blokuje inne tenanty), nawet jeśli samo zatrzymanie
kontenera już nie jest tym, co psuje inne certyfikaty. Sprawdź log
(`/var/log/registro-certificate.log`) jeśli chcesz się upewnić.

## 7.6 — NIEODWRACALNE: zdejmij stack z serwera

**Punkt bez odwrotu.** Poniższe usuwa kontenery, sieć i **wolumeny** (razem z bazą danych tego
klienta) na stałe. Zweryfikuj PRZED uruchomieniem:
- [ ] Krok 7.3 (ostatni backup) zakończył się sukcesem i snapshot istnieje w `restic snapshots`.
- [ ] Krok 7.4 (odłączenie od brzegu) zakończony — domena już nie routuje do tego stacka.
- [ ] Masz nazwę sluga poprawnie wpisaną — to polecenie nie pyta o potwierdzenie po nazwie.

> **Zdjęcia/pliki klienta (`storage-app-public`, `storage-app-private`) są już objęte krokiem 7.3.**
> Od Fazy 2 planu dwóch maszyn `tenant-backup.sh` (i `apply.sh`'s własny krok backupu) kopiują OBA te
> wolumeny do TEGO SAMEGO snapshota restica co `mysqldump` — żadna ręczna kopia nie jest już
> potrzebna, o ile krok 7.3 zakończył się sukcesem. Zobacz Część 8.1/8.6 (przywracanie plików) i
> `tenant-apply.md` dla mechanizmu. Jeśli krok 7.3 akurat zgłosił `DEGRADED` z powodu wolumenu (a nie
> samej bazy) — dopiero WTEDY sięgnij po ręczną kopię niżej, zanim pójdziesz do 7.6:

```bash
docker run --rm -v tenant-nazwaklienta_storage-app-public:/data \
  -v /opt/registro/tenant-backups/nazwaklienta:/backup alpine \
  tar czf /backup/storage-app-public-$(date +%Y%m%d).tar.gz -C /data .
```

> Usunięcie sieci jest odsprzęgnięte od `&&`-łańcucha celowo: jeśli sieć już nie istnieje (albo z
> jakiegoś innego powodu nie da się jej usunąć), to NIE powinno zablokować usunięcia katalogu —
> katalog zostający przy zatrzymanych/usuniętych kontenerach jest właśnie tym stanem, przed którym
> ostrzega krok 7.5.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /opt/stacks/nazwaklienta && \
  docker compose -f docker-compose.prod.yml -f docker-compose.tenant-network.override.yml \
    down --volumes --remove-orphans && \
  { docker network rm tenant-nazwaklienta-edge || echo "siec nie usunieta (juz nie istniala? sprawdz recznie: docker network ls | grep nazwaklienta)"; } && \
  cd /opt/stacks && rm -rf nazwaklienta'
```

## 7.7 — Weryfikacja końcowa

```bash
ssh deploy@srv1342834.hstgr.cloud '
  echo "kontenery:"; docker ps -a --format "{{.Names}}" | grep "^tenant-nazwaklienta-" || echo "  (brak — OK)"
  echo "wolumeny:";   docker volume ls --format "{{.Name}}" | grep "^tenant-nazwaklienta_" || echo "  (brak — OK)"
  echo "katalog:";    [ -d /opt/stacks/nazwaklienta ] && echo "  WCIĄŻ ISTNIEJE — nie powinien" || echo "  (brak — OK)"
  /opt/registro/tenant-check.sh; echo "tenant-check kod: $?"'
```

Poczekaj do 15 minut i sprawdź, że certyfikat przestał obejmować tę nazwę:

```bash
ssh root@srv1342834.hstgr.cloud 'certbot certificates --cert-name registrolabs.com 2>/dev/null | grep -o "nazwaklienta\.[a-z.]*" || echo "usunięte z certyfikatu — OK"'
```

## 7.8 — Retencja prawna: co zostaje, gdzie, na jak długo

| Co | Gdzie | Jak długo | Dlaczego |
|---|---|---|---|
| Faktury/płatności/wypożyczenia (anonimizowane, jeśli `purge` zdążył przejść) | Wyłącznie w `mysqldump` z kroku 7.3, wewnątrz `/opt/registro/tenant-backups/nazwaklienta/repo` na serwerze | **6 lat od zamknięcia** (`legal_records_years`, Art. 112 VAT) | Po kroku 7.6 to jedyna kopia — żywa baza już nie istnieje |
| To samo, jeśli `purge` NIE zdążył przejść przed 7.6 (patrz 7.2) | Ten sam backup | 6 lat, ale **z danymi osobowymi wciąż czytelnymi** | Anonimizacja nigdy nie ruszyła dla tej organizacji — świadomy kompromis, nie błąd |
| Pliki klienta (zdjęcia, logo, eksporty RODO) | W TYM SAMYM snapshocie restica co `mysqldump` z kroku 7.3, o ile ten krok zakończył się `OK` (nie `DEGRADED`) | Tyle samo co baza — jeden snapshot, jedna retencja | `tenant-backup.sh`/`apply.sh` archiwizują `storage-app-public`/`storage-app-private` razem z bazą od Fazy 2 planu dwóch maszyn (patrz Część 8.6) |

**Nazwana, nierozwiązana luka: nic w kodzie nie chroni `/opt/registro/tenant-backups/nazwaklienta`
przed przedwczesnym usunięciem.** Nie ma retention locka, nie ma automatycznego hard-delete po 6
latach (`5.4` w `tenant-lifecycle.md`'s follow-up table jest opisane jako „Planned", nieukończone).
Dopilnowanie tych 6 lat jest dziś wyłącznie odpowiedzialnością operatora — zapisz sobie datę zamknięcia
i nie kasuj tego katalogu przed nią. Osobna, także nierozwiązana luka: wcześniejsze, rutynowe
snapshoty tego samego repozytorium (sprzed zamknięcia) zawierają nieanonimizowane dane osobowe —
anonimizacja `organizations:purge` dotyka tylko żywej bazy, nigdy historii backupów.

---

# CZĘŚĆ 8 — Przywracanie z backupu

**Przetestowane end-to-end 2026-08-09** na jednorazowym, wyrzucanym kontenerze MySQL + repo restic —
nie na serwerze produkcyjnym ani na deweloperskiej bazie. Dokładna sekwencja poleceń niżej to to,
co faktycznie zostało uruchomione, nie teoria.

## 8.1 — Czym JEST i czym NIE JEST ten backup

`tenant-backup.sh`/`apply.sh` backupują **jeden snapshot restica zawierający TRZY rzeczy**: zrzut
`mysqldump` całej bazy tego klienta, oraz wolumeny `storage-app-public` i `storage-app-private`
(zdjęcia sprzętu, logo, eksporty RODO — od Fazy 2 planu dwóch maszyn; wcześniej te dwa wolumeny nie
były objęte niczym, patrz 7.8). Restic kompresuje i deduplikuje wszystko trzy do jednego repo pod
`/opt/registro/tenant-backups/<slug>/repo`. To, co wraca z restica dla bazy, to **plik `.sql`, nie
działająca baza danych** — trzeba go jeszcze wczytać do MySQL (krok 8.4). Pliki użytkownika wracają
jako katalog/archiwum — krok 8.6.

**Powtórka z Części 6:** repozytorium i jego hasło leżą na tym samym serwerze co dane źródłowe. To
jest redundancja dyskowa (chroni przed „ktoś przypadkiem zrobił `DROP TABLE`"), **nie disaster
recovery** (nie chroni przed awarią całego serwera).

## 8.2 — Listowanie snapshotów

```bash
ssh deploy@srv1342834.hstgr.cloud '
  export RESTIC_REPOSITORY=/opt/registro/tenant-backups/nazwaklienta/repo
  export RESTIC_PASSWORD_FILE=/opt/registro/tenant-backups/nazwaklienta/password
  restic snapshots --host tenant-nazwaklienta'
```

Zobaczysz tabelę: ID snapshota, data, tagi (`slug=...`, `scheduled` dla cron, brak `scheduled` dla
ręcznego/`apply`-owego). ID najnowszego snapshota lub słowo `latest` — oba działają w krokach niżej.

## 8.3 — Przywrócenie bazy danych (jeden z TRZECH wpisów w tym snapshocie)

> `restic dump` strumieniuje zawartość pliku z backupu prosto na stdout — nie trzeba nic
> rozpakowywać na dysk osobno. Działa identycznie dla `latest` i dla konkretnego ID snapshota.

> Krok 1: znajdź dokładną ścieżkę pliku `.sql` wewnątrz snapshota — to plik tymczasowy z `mktemp`
> (`tenant-backup.sh`/`apply.sh` obie go tak tworzą), więc nazwa jest za każdym razem inna. `restic ls
> latest` pokaże też `storage-app-public`/`storage-app-private` w osobnym, też tymczasowym katalogu
> (`<slug>-backup-files-XXXXXX/`) — to jest krok 8.6, nie ten.

```bash
ssh deploy@srv1342834.hstgr.cloud '
  export RESTIC_REPOSITORY=/opt/registro/tenant-backups/nazwaklienta/repo
  export RESTIC_PASSWORD_FILE=/opt/registro/tenant-backups/nazwaklienta/password
  restic ls latest'
```

Zobaczysz jedną ścieżkę, np. `/tmp/nazwaklienta-backup-AbCdEf.sql`. Użyj jej dokładnie takiej, jaka
jest, w kroku 2:

```bash
ssh deploy@srv1342834.hstgr.cloud '
  export RESTIC_REPOSITORY=/opt/registro/tenant-backups/nazwaklienta/repo
  export RESTIC_PASSWORD_FILE=/opt/registro/tenant-backups/nazwaklienta/password
  restic dump latest /tmp/nazwaklienta-backup-AbCdEf.sql > /tmp/restored-nazwaklienta.sql'
```

Sprawdź, że coś realnego przyszło:

```bash
tail -5 /tmp/restored-nazwaklienta.sql
```

Musisz zobaczyć `-- Dump completed on ...` — dokładnie ten sam marker, którego `tenant-backup.sh`
pilnuje PRZED wysłaniem do restica (nigdy nie backupuje obciętego zrzutu), więc jego obecność w
przywróconym pliku też jest dowodem, że backup był kompletny w momencie zapisu.

**Restic wspiera też przywrócenie na dysk zamiast do stdout** (`restic restore <ID> --target <katalog>
--include <ścieżka>` dla jednego pliku, bez `--include` dla całego snapshota) — w tym repozytorium
oba warianty dają dziś ten sam efekt, bo każdy snapshot zawiera dokładnie jeden plik.

## 8.4 — Wczytanie zrzutu do MySQL (dopiero to jest "przywrócona baza")

> Sam plik `.sql` nikomu nic nie daje, dopóki nie trafi z powrotem do silnika bazy. Poniżej:
> przywrócenie NA ISTNIEJĄCY, żywy stack tego samego klienta (np. po błędnej migracji) — dla innych
> scenariuszy (nowy serwer, inny slug) zmień tylko cel. **To NADPISUJE każdą tabelę, która jest w
> zrzucie, aktualną zawartością bazy** (`mysqldump`'s domyślne `DROP TABLE IF EXISTS` przed każdym
> `CREATE TABLE`) — wszystko zapisane w tej bazie PO powstaniu tego zrzutu i PRZED tym poleceniem
> zniknie. Jeśli chcesz to zachować, zrób z tego osobny backup, zanim pójdziesz dalej.

> `.env.secrets` jest napisany, żeby go ŹRÓDŁOWAĆ (`source`), nie grepować — wartości są
> w pojedynczych cudzysłowach (`DB_ROOT_PASSWORD='...'`), dokładnie tak jak robi to
> `tenant-backup.sh` i `apply.sh` same. `grep | cut` zostawiłby te cudzysłowy jako część hasła i
> `mysql` odrzuciłby login — a `artisan down` już by zdążył wykonać się wcześniej w tym samym
> łańcuchu, zostawiając aplikację w trybie konserwacji BEZ przywróconych danych. Zweryfikowane
> lokalnie (patrz 8.5) — dokładnie ta komenda, prawdziwe źródłowanie, prawdziwe logowanie do MySQL.
>
> `horizon`/`scheduler` zatrzymane na czas importu — `artisan down` blokuje tylko ruch HTTP na
> kontenerze `app`, kolejka i harmonogram działałyby dalej i pisałyby do tej samej bazy, w której
> import robi `DROP TABLE`/`CREATE TABLE` tabela po tabeli, nieatomowo.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /opt/stacks/nazwaklienta && \
  set -a && . .env.secrets && set +a && \
  DB_DATABASE="$(grep -m1 "^DB_DATABASE=" .env | cut -d= -f2-)" && \
  DB_DATABASE="${DB_DATABASE:-registro}" && \
  docker compose -f docker-compose.prod.yml exec -T app php artisan down --retry=15 && \
  docker compose -f docker-compose.prod.yml stop horizon scheduler && \
  docker compose -f docker-compose.prod.yml exec -T mysql \
    mysql -uroot -p"$DB_ROOT_PASSWORD" "$DB_DATABASE" < /tmp/restored-nazwaklienta.sql && \
  docker compose -f docker-compose.prod.yml up -d horizon scheduler && \
  docker compose -f docker-compose.prod.yml exec -T app php artisan up'
```

**Jeśli cokolwiek w tym łańcuchu zawiedzie w połowie** — aplikacja może zostać w trybie konserwacji
i/lub z zatrzymanym `horizon`/`scheduler`. To celowe (lepsze niż obsługiwanie ruchu w połowie
nadpisanej bazy) — odczytaj błąd, napraw przyczynę, i albo uruchom ten sam blok ponownie od
początku, albo ręcznie: `docker compose -f docker-compose.prod.yml up -d horizon scheduler && \
docker compose -f docker-compose.prod.yml exec -T app php artisan up`.

`artisan down` przed wczytaniem, `artisan up` po — zapobiega temu, żeby aplikacja obsługiwała ruch
w trakcie, gdy baza jest w połowie nadpisywana. Zweryfikuj skutek zapytaniem, nie kodem wyjścia:

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /opt/stacks/nazwaklienta && \
  docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="
    echo App\Models\Organization::count().PHP_EOL;
  "'
```

## 8.5 — Dowód, że to działa: co faktycznie zostało uruchomione (2026-08-09)

Jednorazowy kontener `mysql:8.0` (nazwa `restic-drill-mysql`, nigdy nie część żadnego compose
projektu tego repo) + jednorazowe repo restic w katalogu roboczym poza projektem:

1. Utworzono tabelę z dwoma wierszami-znacznikami, zrobiono `mysqldump` **dokładnie tymi samymi
   flagami co `tenant-backup.sh`** (`--single-transaction --routines --triggers --events`),
   zweryfikowano marker `-- Dump completed`.
2. `restic init` + `restic backup dump.sql --host tenant-drill --tag slug=drill --tag scheduled`.
3. **Zniszczono oryginał**: `rm dump.sql`, `DROP DATABASE` + `CREATE DATABASE` pusta w miejscu
   oryginalnej — potwierdzono `SHOW TABLES` puste.
4. `restic snapshots` pokazał snapshot. `restic restore latest --target ... --include /data/dump.sql`
   przywrócił plik bajt w bajt (potwierdzony marker `-- Dump completed` w przywróconym pliku).
   Osobno zweryfikowano też `restic dump latest <ścieżka>` (strumień na stdout) na osobnym,
   minimalnym przykładzie — obie ścieżki restorowania działają.
5. Wczytano przywrócony plik z powrotem (`mysql ... < restored/data/dump.sql`) do świeżo utworzonej
   bazy — `SELECT * FROM t` pokazał **oba oryginalne wiersze, identyczne co do treści**.
6. Posprzątano: `docker rm -f restic-drill-mysql`, usunięto katalog roboczy (przez pomocniczy
   kontener `alpine`, bo pliki repo restic są zapisane jako root wewnątrz kontenera i host-owy
   użytkownik nie ma do nich praw zapisu — samo w sobie dobre do wiedzenia, jeśli kiedyś sprzątasz
   repo restic ręcznie na serwerze, gdzie `deploy` nie jest rootem).

Żaden z powyższych kroków nie dotknął `registro-app`/`registro-mysql` (kontenerów deweloperskich
tego repo) ani serwera produkcyjnego.

## 8.6 — Przywrócenie plików klienta (`storage-app-public`/`storage-app-private`)

> Te dwa wolumeny lądują w TYM SAMYM snapshocie co zrzut bazy (krok 8.3) — jeden ID snapshota, nie
> trzeba szukać drugiego, bliskiego w czasie. `restic ls latest` (krok 8.2/8.3) pokaże katalog
> `<slug>-backup-files-XXXXXX/storage-app-public` i `.../storage-app-private` obok pliku `.sql`.

> `restic dump` obsługuje też CAŁE katalogi, nie tylko pojedyncze pliki — z `--archive tar` (lub
> `zip`) strumieniuje je jako archiwum na stdout, zamiast pojedynczego pliku. Działa identycznie do
> kroku 8.3, tylko z dodatkową flagą.

**Krok 1 — TYLKO podgląd, na host, żeby zweryfikować co jest w backupie.** Ten katalog jest
jednorazowy i wyłącznie do oglądania — jego właściciel na hoście NIE MA znaczenia i NIE jest tym,
co trafi do wolumenu (patrz krok 2):

```bash
ssh deploy@srv1342834.hstgr.cloud '
  export RESTIC_REPOSITORY=/opt/registro/tenant-backups/nazwaklienta/repo
  export RESTIC_PASSWORD_FILE=/opt/registro/tenant-backups/nazwaklienta/password
  restic dump latest /tmp/nazwaklienta-backup-files-AbCdEf/storage-app-public --archive tar' \
  > /tmp/nazwaklienta-storage-app-public.tar
mkdir -p /tmp/nazwaklienta-restored-public
tar -xf /tmp/nazwaklienta-storage-app-public.tar -C /tmp/nazwaklienta-restored-public
find /tmp/nazwaklienta-restored-public -type f | head
```

**Krok 2 — wgranie z powrotem do wolumenu (co faktycznie zobaczy aplikacja).** NIE użwaj kopii z
kroku 1 dla tego — strumieniuj z restica prosto do wolumenu przez jeden uprzywilejowany kontener,
tą samą zasadą co backup (`tenant-apply.md` → `stage_volume()`): `cp`/`tar -x` jako zwykły,
nieuprzywilejowany operator SSH (`deploy`, nie root) nie potrafi ustawić właściciela na UID 1000
(`laravel`, ADR-013) — plik wychodzi spod `tar -x` własnością TEGO, kto go rozpakował (`deploy`,
zwykle inny UID), a proces aplikacji, który potem próbuje w te pliki pisać (nowe wgrania w tym samym
katalogu, nadpisanie, usunięcie), dostaje `Permission denied`, bo nie jest ani właścicielem, ani w
jego grupie. Root wewnątrz kontenera obchodzi to przy rozpakowywaniu, a `chown -R 1000:1000` na
końcu TEGO SAMEGO uprzywilejowanego `docker run` przestawia własność na dokładnie ten UID, którego
oczekuje aplikacja — niezależnie od tego, jakim userem operator jest zalogowany po SSH:

```bash
ssh deploy@srv1342834.hstgr.cloud '
  export RESTIC_REPOSITORY=/opt/registro/tenant-backups/nazwaklienta/repo
  export RESTIC_PASSWORD_FILE=/opt/registro/tenant-backups/nazwaklienta/password
  restic dump latest /tmp/nazwaklienta-backup-files-AbCdEf/storage-app-public --archive tar' \
| ssh deploy@srv1342834.hstgr.cloud '
  docker run --rm -i --user 0:0 -v tenant-nazwaklienta_storage-app-public:/dest \
    debian:bookworm-slim sh -c "tar -x -C /dest --strip-components=3 && chown -R 1000:1000 /dest"'
```

`--strip-components=3` drops the snapshot's own leading `tmp/<slug>-backup-files-XXXXXX/storage-app-public/`
prefix (three path segments, fixed by `mktemp`'s own naming convention in `tenant-backup.sh`/
`apply.sh` — see that file), so only the CONTENT of the volume lands directly under `/dest`. Powtórz
dla `storage-app-private` (druga ścieżka z tego samego `restic ls latest`).

**Restic wspiera też `restic restore latest --target <katalog> --include <ścieżka>`** jako
alternatywę dla kroku 1 (podgląd na host) — nie zmienia nic w kroku 2, który zawsze idzie przez
root+`chown`, niezależnie od tego, którym poleceniem odtworzyłeś podgląd.

**Zweryfikowane end-to-end 2026-08-10** (patrz 8.7, punkt dodatkowy): odtworzono do ŚWIEŻEGO
wolumenu dokładnie tą metodą (root-extract + `chown -R 1000:1000`), potem uruchomiono jednorazowy
kontener DZIAŁAJĄCY JAKO UID 1000 (ten sam, którego używa `laravel` w obrazie produkcyjnym) i
potwierdzono, że potrafi zapisać NOWY plik do przywróconego katalogu — nie tylko go odczytać. Bez
`chown` ten sam zapis kończy się `Permission denied` (zweryfikowane oboma wariantami wprost).

## 8.7 — Dowód, że backup plików działa: co faktycznie zostało uruchomione (2026-08-10)

Osobno od 8.5 (który testował wyłącznie bazę), zweryfikowano end-to-end wolumeny — jednorazowy
wolumen docker + jednorazowe repo restic, poza projektem, nigdy nie dotykając serwera ani bazy
deweloperskiej:

1. Utworzono wolumen, wgrano do niego plik tekstowy i plik binarny (4 KiB losowych danych) jako
   UID 1000 (ten sam UID co `laravel` w obrazie produkcyjnym, ADR-013), zapisano ich sumy `sha256`.
2. Uruchomiono DOKŁADNIE `stage_volume()` wyjęte z `tenant-backup.sh` (nie przepisane na nowo) —
   skopiowało wolumen do katalogu na hoście przez jednorazowy kontener z `--user 0:0`.
3. **Znaleziona i naprawiona w trakcie tej walidacji usterka projektowa:** `cp -a /src/. /dest/`
   (GNU coreutils) nie tylko kopiuje zawartość `/src` DO `/dest` — nadpisuje też WŁASNE
   uprawnienia/właściciela `/dest` metadanymi źródła (`root:root`, bo katalog wolumenu sam jest
   root-owned). Bez poprawki katalog docelowy (utworzony wcześniej przez `deploy`) cichcem zmieniał
   właściciela na roota, a późniejsze `rm -rf` przez `deploy` (bez sudo na tej maszynie) kończyło się
   `Permission denied` — sprzątanie po backupie zaczynało się psuć od DRUGIEGO uruchomienia. Fix:
   `chown -R $(id -u):$(id -g) /dest` jako druga komenda w tym samym uprzywilejowanym `docker run`,
   zanim kontener się kończy. Zweryfikowano oba warianty wprost (bez i z `chown`) — bez niego
   `rm -rf` przez zwykłego użytkownika kończyło się błędem; z nim — nie.
4. `restic backup dump.sql <skopiowany-katalog> --host tenant-drill ...` — jeden snapshot, oba wpisy
   widoczne w `restic ls latest`.
5. **Zniszczono oryginalny wolumen**: `docker volume rm -f`, potwierdzono `docker volume inspect`
   zwraca błąd (nie istnieje).
6. Przywrócono OBOMA sposobami: `restic restore latest --target ...` i osobno `restic dump latest
   <ścieżka> --archive tar` rozpakowane ręcznie — **sumy `sha256` obu plików identyczne z krokiem 1**
   w obu wariantach.
7. **Osobno zweryfikowano fail-safe przeciwko cichemu tworzeniu wolumenu**: wywołano `stage_volume()`
   z nazwą wolumenu, który nigdy nie istniał. `docker volume inspect` (krok PRZED jakimkolwiek `docker
   run -v`) zwrócił błąd, funkcja zakończyła się kodem 1 BEZ wywołania `docker run` w ogóle —
   `docker volume ls` po teście potwierdził, że żaden nowy wolumen o tej nazwie nie powstał (dokładnie
   ten bug, przed którym ten krok ma chronić: `docker run -v nieistniejący:/sciezka` cichcem tworzy
   pusty wolumen zamiast błędu).
8. **Znaleziona w przeglądzie infrastrukturalnym, naprawiona i zweryfikowana wprost:** kroki 1-7
   dowodzą tylko, że przywrócone bajty są IDENTYCZNE z oryginałem — nie dowodzą, że aplikacja może
   ich potem UŻYWAĆ. Odtworzono ten sam plik do ŚWIEŻEGO wolumenu, symulując realny serwer, gdzie
   `deploy` NIE jest UID 1000 (użyto jawnie UID 1002, przykład z ADR-010) — staging przed backupem
   przestawiał więc właściciela na 1002:1002, nie na 1000 jak app oczekuje (ADR-013). Odtworzenie BEZ
   końcowego `chown -R 1000:1000` (sam root-extract z `tar`) zostawiało pliki 1002:1002 — kontener
   uruchomiony JAKO UID 1000 (realne `laravel`) dostawał `Permission denied` przy próbie zapisu
   nowego pliku. Z `chown -R 1000:1000` jako ostatnim krokiem tego samego uprzywilejowanego
   `docker run` — ten sam zapis się udawał, i dodatkowo potwierdzono odczyt wcześniej przywróconej
   zawartości. Stąd Część 8.6 wymaga TEGO SAMEGO `chown` po stronie przywracania, nie tylko backupu.

Żaden z powyższych kroków nie dotknął serwera produkcyjnego ani deweloperskiej bazy/kontenerów tego
repo.

---

# Czego ten dokument jeszcze nie umie

Uczciwa lista, żeby nie zaskoczyła Cię w trakcie:

- **Części 1 i 2 nigdy nie były wykonane na serwerze.** Spodziewaj się poprawek.
- ~~Krok 4.1 (nowy link do hasła) nie ma jeszcze sprawdzonej komendy~~ — naprawione:
  `registro:password-setup-link`, zweryfikowane lokalnie 2026-08-09, pokryte testami.
- ~~Certyfikat dla nowego klienta trzeba dołożyć ręcznie~~ — naprawione: `sync-certificate.sh`
  enumeruje dziś też podpięte stacki dedykowane (krok 2.5), żaden ręczny krok już nie jest
  potrzebny, poza zwykłym odczekaniem do 15 minut na najbliższy przebieg crona.
- ~~Nie ma tu usuwania klienta~~ — naprawione: Część 7. **Nadal nierozwiązane w kodzie** (nazwane
  wprost w 7.8, nie obejście, tylko brak): nic nie chroni `/opt/registro/tenant-backups/<slug>`
  przed przedwczesnym usunięciem w trakcie 6-letniej retencji prawnej; brak automatycznego
  hard-delete rekordów prawnych po upływie retencji (`tenant-lifecycle.md`, follow-up „5.4",
  status „Planned"); brak flagi „pomiń okres karencji" dla pojedynczej organizacji; wcześniejsze
  snapshoty backupu (sprzed zamknięcia) zawierają nieanonimizowane dane osobowe na zawsze, bo
  anonimizacja dotyka tylko żywej bazy.
- ~~Nie ma tu przywracania z backupu~~ — naprawione: Część 8, przetestowane end-to-end 2026-08-09
  (patrz 8.5).
- ~~Pliki klienta (`storage-app-public`/`storage-app-private`) nie są objęte backupem w ogóle~~ —
  naprawione (Faza 2 planu dwóch maszyn): objęte tym samym snapshotem restica co baza, przetestowane
  end-to-end 2026-08-10 (patrz 7.8, 8.1, 8.6, 8.7).
- **Poczta wychodzi z prywatnego adresu Gmail.** Klient zobaczy `Registro <patryk3580@gmail.com>`.
  Do naprawienia przy pierwszym prawdziwym kliencie.

---

# CZĘŚĆ 9 — Przeniesienie klienta między maszynami / zmiana domeny (S3, S4, S8)

Faza 3 planu dwóch maszyn (`~/.claude/plans/dwie-maszyny-uat-preprod.md`). Jedna procedura na trzy
scenariusze, bo różnią się tylko ostatnim krokiem:

- **S3** — klient zostaje u nas: PreProd dostaje domenę klienta, **ta sama maszyna, ta sama baza**.
- **S4** — klient jedzie na swój VPS: stack przenosi się na maszynę, której nie kontrolujemy.
- **S8** — maszyna padła: stack odtwarzany jest na nowej maszynie z backupu.

**Ta procedura jest opisem kroków, nie skryptem.** Rozważone i odrzucone: automat przechodzący
przez granicę SSH między dwiema maszynami, z których jednej możemy nie kontrolować (S4) albo która
może już nie istnieć (S8). Taki automat psuje się w sposób, którego nikt nie zdiagnozuje o 2 w
nocy. Zamiast tego — kompozycja już istniejących, już przetestowanych elementów: `apply.sh`
(jawny argument `[hosts]`), backup/restore restica (Część 8, już przetestowany end-to-end), i
zwykłe `scp`/`rsync` operatora między maszynami, których żaden skrypt tego repo nie orkiestruje.
Kroki destrukcyjne i weryfikacyjne są oznaczone wprost, tak jak w Części 7/8.

## 9.0 — Dwie ścieżki, nie trzy

**Ścieżka A (S3): sama zmiana domeny, ta sama maszyna.** Baza, wolumeny, kontenery — nic się nie
przenosi, bo to wciąż ten sam MySQL. Jedyna zmiana to `TENANT_HOSTS`/`APP_URL`/`APP_DOMAIN` i
certyfikat. Zobacz 9.3.

**Ścieżka B (S4/S8): nowa maszyna.** Dane muszą fizycznie się przenieść — dump bazy i oba wolumeny
storage, dokładnie to, co Część 8 już umie przywracać. Zobacz 9.4. S4 i S8 różnią się tylko tym,
skąd bierzesz backup (S4: żywa maszyna źródłowa, `tenant-backup.sh`/`apply.sh` mogą zrobić świeży
przed przeniesieniem; S8: maszyna nie istnieje, jedyne co masz to ostatni backup, który przeżył —
patrz 9.7, luka R2) i jaką domenę wpisujesz (S4: domena klienta; S8: zwykle ta sama domena co
wcześniej, tylko wskazująca na nową maszynę).

## 9.1 — Trzy kategorie: co się kopiuje, co się regeneruje, co się przepisuje

**Kopiowane dosłownie — zmiana czegokolwiek z tej listy niszczy dane:**

- **`.env.secrets` w całości.** `APP_KEY` szyfruje `audit_logs` (`EncryptedJsonCast`) —
  regeneracja czyni każdy istniejący zaszyfrowany rekord trwale nieczytelnym, bez ścieżki
  odzyskania. `apply.sh` już odmawia nadpisania tego pliku, kiedy istnieje (patrz jego własny
  komentarz przy `SECRETS_FILE`) — ta procedura to wykorzystuje, nie obchodzi.
- **`.env.bak-manual`**, jeśli istnieje — sekrety biznesowe (mail, SMS, mapy, płatności), których
  `apply.sh` nie potrafi wymyślić samo. `apply.sh` dokleja tę zawartość do wygenerowanego `.env`
  przy każdym uruchomieniu (patrz jego komentarz tuż przed doklejeniem) — bez skopiowania tego
  pliku, klient wyląduje na docelowej maszynie bez działającej poczty/SMS/płatności.

**Wniosek o `DB_PASSWORD`/`DB_ROOT_PASSWORD`/`REDIS_PASSWORD` — zależnie od METODY:**

Te trzy wartości są tym, co faktycznie trzyma w sobie wolumen MySQL i AOF Redisa. Ta procedura
(9.4) przenosi dane wyłącznie przez **dump + przywrócenie** (dokładnie mechanizm Części 8, już
przetestowany) — **nigdy** przez fizyczne przeniesienie surowego wolumenu `mysql_data`/
`redis_data`. To rozstrzyga pytanie: przy dump+restore docelowa maszyna zawsze startuje ze
**świeżym, pustym** wolumenem MySQL/Redis, który inicjalizuje się z hasłami z `.env.secrets` w
momencie, gdy jest pusty — niezależnie od tego, czy te hasła są identyczne z maszyną źródłową, czy
nowo wygenerowane. Sam `mysqldump`/przywrócenie łączy się potem przez klienta `mysql` używającego
DOKŁADNIE tych haseł, więc **nie muszą** być takie same jak na źródle.

**Gdyby ktoś kiedyś przenosił surowy wolumen `mysql_data`/`redis_data` bezpośrednio** (`docker
volume` eksport/rsync katalogu wolumenu — **czego ta procedura nie robi i czego dziś nic w tym
repo nie wspiera**), byłoby odwrotnie: MySQL/Redis czytają `MYSQL_ROOT_PASSWORD`/`requirepass`
tylko przy inicjalizacji PUSTEGO wolumenu, więc przeniesiony, już zainicjalizowany wolumen
IGNORUJE nowe wartości z `.env.secrets` — hasła musiałyby wtedy przenieść się bajt w bajt razem z
wolumenem, inaczej aplikacja nie połączy się z własną bazą. **Ta procedura tego nie robi**, więc
`.env.secrets`' `DB_PASSWORD`/`DB_ROOT_PASSWORD`/`REDIS_PASSWORD` mogą swobodnie być inne na
docelowej maszynie niż na źródłowej — jedyna wartość z tego pliku, która MUSI przeżyć bajt w bajt,
to `APP_KEY`. W praktyce prościej i tak skopiować cały plik (patrz wyżej) — ale to z powodu
`APP_KEY`, nie haseł bazy.

**Regeneruje się na nowej maszynie — kopiowanie tego byłoby błędem:** porty (`HTTP_PORT_V4`/
`HTTPS_PORT_V4` — `allocate_ports()` skanuje TĘ maszynę), podsieć `/29` (`allocate_subnet()` —
skanuje sieci Dockera TEJ maszyny), `TRUSTED_PROXIES_CIDR` (pochodna tej samej podsieci), config
nginxa tenanta, vhost brzegu, linia certyfikatu (`CERT_DIR` tej maszyny, z `.env` stacka legacy),
ścieżka repozytorium restic (`/opt/registro/tenant-backups/<slug>/repo` na NOWEJ maszynie — nowe,
puste repo, nie kontynuacja starego; stary backup zostaje osobno, patrz 9.4 krok 7).

**Przepisuje się pod nową domenę:** `APP_URL`, `APP_DOMAIN`, `TENANT_HOSTS` — wszystkie trzy pisze
`apply.sh` samo, gdy dostanie jawny argument `[hosts]`.

## 9.2 — Pułapka: `apply.sh` regeneruje `.env` w całości przy KAŻDYM uruchomieniu

`apply.sh` nie ma trybu "zmień tylko domenę, zostaw resztę" — cały `.env` (poza
`REGISTRO_VERSION` i doklejonym `.env.bak-manual`) jest tworzony od zera przy każdym apply, z
argumentu `[hosts]` tego konkretnego wywołania. **Jeśli kolejny, rutynowy `apply` (wydanie nowej
wersji, miesiące później) pominie argument `[hosts]`, domena cicho wraca do wartości domyślnej tej
maszyny** (`<slug>.<APP_DOMAIN maszyny>`) — bez błędu, bez ostrzeżenia, `apply-status` dalej czyta
`OK`. Zweryfikowane wprost w 9.6: identyczny `apply` bez `[hosts]` po migracji cicho nadpisał
`migdrillmoved.registrolabs.com` z powrotem na `migdrill.registrolabs.com`.

**Zasada operacyjna, nie tylko techniczna: każdy przyszły `apply` dla tenanta po migracji/zmianie
domeny MUSI dalej podawać ten sam, pełny `[hosts]`.** Zapisz go tam, gdzie go zobaczysz przy
następnym wydaniu (np. w notatce operatora przy tym kliencie) — nie polegaj na pamięci.

## 9.3 — Ścieżka A: sama zmiana domeny, ta sama maszyna (S3)

1. Klient wskazuje swoją domenę na TĘ maszynę (A/AAAA/CNAME na ten sam adres IP co dzisiejsza
   domena PreProd). Musi już rozwiązywać się, zanim pójdziesz dalej — `apply.sh`'s `check_dns()`
   i tak to wymusi.
2. ```bash
   ssh deploy@<ta-maszyna> '/opt/registro/apply.sh nazwaklienta v0.14.0 klient.pl'
   ```
   Ten sam `apply`, jedyna różnica to jawny `[hosts]` zamiast pominięcia go. Porty, podsieć,
   `TRUSTED_PROXIES_CIDR`, dane — wszystko zostaje, bo to ten sam `STACK_DIR`/sieć/wolumeny (patrz
   `allocate_ports()`/`allocate_subnet()` w 9.1: obie CZYTAJĄ istniejącą wartość z `.env`/sieci,
   zamiast alokować od nowa, gdy już istnieje).
3. Poczekaj na najbliższy przebieg `sync-certificate.sh` (≤15 min, patrz krok 2.5) albo uruchom go
   ręcznie jako root, żeby nie czekać — certyfikat rozszerzy się o `klient.pl`. **Stara nazwa
   (`nazwaklienta.registroapps.com`) znika z `TENANT_HOSTS` w tym samym momencie** (argument
   `[hosts]` PODMIENIA całą listę, nie dokleja) — jeśli chcesz okno przejściowe, w którym obie
   nazwy działają, podaj obie, przecinkiem: `nazwaklienta v0.14.0 nazwaklienta.registroapps.com,klient.pl`.
4. Zweryfikuj jak w 9.5 (punkty 4-6 nie dotyczą — nic się nie przeniosło; punkty 1-2 owszem, pod
   nowym adresem).

## 9.4 — Ścieżka B: przeniesienie na inną maszynę (S4/S8)

**Krok 1 — ostatni backup na maszynie źródłowej** (S4 tylko — S8 nie ma już źródła, przejdź do
kroku 2 z tym, co przetrwało, patrz 9.7):
```bash
ssh deploy@<maszyna-źródłowa> '/opt/registro/tenant-backup.sh nazwaklienta'
```

**Krok 2 — skopiuj poza hostem, zanim cokolwiek innego.** Dwa niezależne transfery, oba przez
kanał, który operator już ma (scp, rsync, cokolwiek poza tym repo — celowo nieautomatyzowane, patrz
nagłówek tej części):
```bash
scp deploy@<źródło>:/opt/stacks/nazwaklienta/.env.secrets ./nazwaklienta.env.secrets
scp deploy@<źródło>:/opt/stacks/nazwaklienta/.env.bak-manual ./nazwaklienta.env.bak-manual  # jeśli istnieje
rsync -a deploy@<źródło>:/opt/registro/tenant-backups/nazwaklienta/ ./nazwaklienta-backup/
```
`nazwaklienta-backup/` zawiera `repo/` (dane restica, root-owned wewnątrz — normalne, patrz 8.5) i
`password`. **To jest jedyna kopia tych danych poza maszyną źródłową w tym momencie** — sprawdź, że
`rsync` faktycznie się powiódł, zanim pójdziesz dalej.

**Krok 3 — na maszynie docelowej, sklonuj PRZED pierwszym `apply`, nie po.** Pułapka: `apply.sh`
klonuje tylko wtedy, gdy `${STACK_DIR}/.git` nie istnieje, a `git clone` odmawia klonowania do
NIEPUSTEGO katalogu — jeśli wgrasz `.env.secrets` jako pierwszy plik w świeżym `/opt/stacks/
nazwaklienta/`, `apply.sh`'s własny `git clone` padnie na "destination path already exists and is
not an empty directory". Klonuj ręcznie najpierw, dokładnie tak jak zrobiłby to `apply.sh`:
```bash
ssh deploy@<maszyna-docelowa> 'git clone https://github.com/patrykgielo/registro.git /opt/stacks/nazwaklienta'
```

**Krok 4 — wgraj sekrety, TERAZ, do już sklonowanego katalogu:**
```bash
scp ./nazwaklienta.env.secrets deploy@<docelowa>:/opt/stacks/nazwaklienta/.env.secrets
ssh deploy@<docelowa> 'chmod 600 /opt/stacks/nazwaklienta/.env.secrets'
scp ./nazwaklienta.env.bak-manual deploy@<docelowa>:/opt/stacks/nazwaklienta/.env.bak-manual  # jeśli istnieje
```

**Krok 5 — pierwszy `apply` na maszynie docelowej, BEZ flag `--name`/`--owner-*`.** To
jest to, co odróżnia ten `apply` od zwykłego nowego-klienta provisioningu: `.env.secrets` już
istnieje (odczytane, nigdy nieregenerowane — patrz 9.1), więc infrastruktura startuje na
migrowanych sekretach, ale baza jest na razie PUSTA (świeży wolumen, migracje przechodzą na czystym
schemacie). Pominięcie flag właściciela jest celowe — `apply.sh` sam to rozpozna i NIE odpali
`registro:tenant-provision` (który stworzyłby drugą, fałszywą organizację):
```bash
ssh deploy@<docelowa> '/opt/registro/apply.sh nazwaklienta v0.14.0 klient.pl'
```
Log powinien pokazać dokładnie: `Not yet provisioned and no --name/--owner-email/--owner-name
given -- infra is up, organization is not.` — to jest oczekiwany, POŚREDNI stan, nie błąd.

**Krok 6 — przywróć bazę.** Dokładnie Część 8.3/8.4, z jedną zmianą: `RESTIC_REPOSITORY`/
`RESTIC_PASSWORD_FILE` wskazują na przeniesiony katalog (`./nazwaklienta-backup/repo` /
`./nazwaklienta-backup/password`), nie na `/opt/registro/tenant-backups/...` na maszynie, która go
nie ma. `restic ls latest`/`restic dump` mogą działać z DOWOLNEJ maszyny mającej dostęp do repo i
hasła — nie muszą biec na docelowej maszynie, ale samo wczytanie do MySQL (`mysql -uroot -p...`)
musi, bo to tamtejszy kontener `mysql`.

**Krok 7 — przywróć pliki.** Dokładnie Część 8.6 (root-extract + `chown -R 1000:1000`), dwa razy —
`storage-app-public` i `storage-app-private` — z tego samego przeniesionego repo co krok 6.

**Krok 8 — zweryfikuj.** Wszystkie sześć punktów z 9.5, bez wyjątków, zanim oddasz klientowi.

## 9.5 — Weryfikacja obowiązkowa, sześć punktów

Żaden z nich nie jest opcjonalny — patrz 9.6 dla dokładnych komend i wyników, które faktycznie to
potwierdziły.

1. **Przeniesiony tenant odpowiada, a właściciel loguje się.** `curl` na `/up` z właściwym
   `Host:` (jak w kroku 2.4), i realne hasło właściciela faktycznie pasuje do przywróconego hasha
   (`Hash::check` albo prawdziwe logowanie przez formularz).
2. **`audit_logs` zapisane PRZED przeniesieniem są czytelne PO nim.** To jest dowód, że `APP_KEY`
   przeżył — jeśli którykolwiek stary wiersz rzuca błędem odszyfrowania przy odczycie, `.env.secrets`
   nie zostało skopiowane poprawnie (albo w ogóle) i trzeba zatrzymać się TERAZ, nie oddawać
   klienta.
3. **Wgrane pliki są obecne I zapisywalne przez aplikację** — nie tylko obecne. Test: aplikacja
   (kontener działający jako UID 1000) faktycznie zapisuje nowy plik do przywróconego katalogu, nie
   tylko go odczytuje.
4. **Porty, podsieć i `TRUSTED_PROXIES_CIDR` docelowej maszyny są jej własne**, nie skopiowane ze
   źródła. Porównaj `docker network inspect tenant-<slug>-edge` (prawda) z `.env`'s
   `TRUSTED_PROXIES_CIDR` (deklaracja) — muszą się zgadzać, i muszą różnić się od maszyny
   źródłowej (chyba że przypadkiem wylosowały ten sam wolny oktet niezależnie — samo w sobie nie
   jest błędem, dopóki `docker network inspect` na docelowej maszynie potwierdza wartość
   niezależnie).
5. **`tenant-check.sh` milczy** na docelowej maszynie dla tego slugu.
6. **Ponowny `apply` z TYM SAMYM `[hosts]` nie cofa zmiany domeny.** Uruchom go jeszcze raz,
   identycznie, i sprawdź `TENANT_HOSTS`/`APP_URL` w `.env` — muszą zostać takie same. (Nie myl
   tego z pułapką z 9.2 — to jest test POPRAWNEGO powtórzenia, nie testu pominięcia argumentu.)

## 9.6 — Dowód, że to działa: co faktycznie zostało uruchomione (2026-08-10)

Dwa katalogi `/opt/stacks` w piaskownicy (`REGISTRO_STACKS_ROOT` na dwie niezależne ścieżki,
symulujące dwie maszyny), dwa niezależne checkouty `LEGACY_APP_DIR`, prawdziwy lokalny `docker
build` tego brancha, throwaway `git` origin, throwaway tag. Nic nie dotknęło serwera produkcyjnego,
deweloperskiej bazy ani działających kontenerów deweloperskich tego repo (`registro-app`/
`registro-mysql`/`registro-redis` — potwierdzone `Up`/`healthy` przed i po).

1. Sprowizjonowano tenanta `migdrill` na symulowanej maszynie źródłowej (`migdrill.registrolabs.com`
   — wildcard DNS `*.registrolabs.com` rozwiązuje realnie, więc `check_dns()` przechodzi bez
   żadnego fake'a). Ustawiono realne hasło właściciela, zapisano jeden wiersz `audit_logs` z
   unikalnym znacznikiem w `new_values` (`MIGRATION-PROOF-2026-08-10-XYZ`), wgrano po jednym pliku
   do `storage-app-public` i `storage-app-private` z zapisanymi sumami `sha256`.
2. **Znaleziona i naprawiona w trakcie tej walidacji, przed jakąkolwiek migracją: `stage_volume()`
   w OBU `apply.sh` i `tenant-backup.sh` nigdy nie ustawiały `--entrypoint`** przy `docker run` na
   prawdziwym obrazie `ghcr.io/patrykgielo/registro`. Ten obraz ma własny `docker/entrypoint.sh`,
   który odmawia startu jako ktokolwiek inny niż `laravel` (`EXPECTED_USER` check) — `--user 0:0`
   nigdy nie docierał do `cp`/`chown`, bo entrypoint zabijał kontener najpierw, `docker run`
   "kończył się sukcesem" startując kontener, który wyszedł z kodem 1 bez zrobienia niczego.
   Skutek na żywej maszynie: **backup obu wolumenów storage tworzył pusty snapshot**. Nie było to
   ciche w sensie „bez żadnego sygnału" — `apply.sh` ustawiał `BACKUP_FAILED`, czyli status
   `DEGRADED` i wyjście 5, a `tenant-backup.sh` kończył się kodem 3 z wpisem `ERROR`. Ciche było
   coś gorszego: **sam zrzut bazy szedł poprawnie**, więc snapshot istniał i wyglądał na dobry, a
   komunikat łatwo przeczytać jako szum przy skądinąd udanym wdrożeniu. Dowiedziałbyś się przy
   odtwarzaniu. Zreprodukowane wprost: `docker run --user 0:0 -v
   wolumen:/src:ro ... obraz sh -c "cp -a ..."` → `❌ CRITICAL: Running as 'root' but expected
   'laravel'`, wyjście 1, `/dest` puste. Naprawa: `--entrypoint sh` + `-c "..."` zamiast `sh -c
   "..."` jako CMD — ten sam, jednowierszowy fix w obu plikach. Zweryfikowane po naprawie: ten sam
   wolumen, ta sama komenda, `cp -a`/`chown` faktycznie wykonane, suma `sha256` zgodna. Prawdopodobny
   powód, czemu to przeszło przez walidację Fazy 2 (8.7): tamten test funkcji `stage_volume()` w
   izolacji najwyraźniej użył innego obrazu niż realny, entrypoint-guarded `registro`.
3. Po naprawie: pełny `apply` na maszynie źródłowej zakończył się `OK` z realnym backupem
   (`Backup complete`), snapshot restica zawierał dump `.sql` I oba wolumeny storage (`restic ls
   latest` pokazał znacznik plik po pliku, w tym `migration-drill/marker.txt`).
4. **Zniszczono maszynę źródłową**, dosłownie: `docker compose down -v` na stacku, `docker network
   rm` na sieci brzegu tego tenanta, `docker compose down` na jego brzegu — zero kontenerów,
   wolumenów, sieci tego slugu pozostało. To jest jedyny sposób, żeby uczciwie przetestować "druga
   maszyna" na jednym Dockerze: ten sam slug na dwóch żywych stackach jednocześnie koliduje po
   nazwach kontenerów/wolumenów (Compose nazywa je po `TENANT_PREFIX`, nie po katalogu projektu) —
   znalezione przy pierwszej próbie ("Already provisioned and consistent" na "świeżej" maszynie
   docelowej okazało się czytaniem NADAL ŻYWYCH kontenerów źródła), naprawione przez pełne zdjęcie
   źródła przed startem celu, dokładnie jak realny S4/S8.
5. Skopiowano `.env.secrets` (potwierdzone `diff`: identyczne bajt w bajt) i katalog backupu
   (`repo/` + `password`, przez pomocniczy kontener root, jak w 8.5/8.7 — pliki restica są
   root-owned) na symulowaną maszynę docelową. `restic check --read-data-subset=100%` na
   PRZENIESIONEJ kopii repo: `no errors were found`.
6. Ręczny `git clone` PRZED pierwszym `apply` (krok 9.4.3), potem `apply` z nowym `[hosts]`
   (`migdrillmoved.registrolabs.com`) i BEZ flag właściciela — log potwierdził dokładnie:
   `Not yet provisioned and no --name/--owner-email/--owner-name given -- infra is up, organization
   is not.` Alokacja portu na docelowej maszynie: `18090`, różna od źródłowej `18080` —
   niezależna, nie skopiowana.
7. Przywrócono bazę (Część 8.4, dokładna komenda) i oba wolumeny (Część 8.6, dokładna komenda) z
   PRZENIESIONEJ kopii repo.
8. **Wszystkie sześć punktów 9.5 zweryfikowane wprost, na przywróconym stanie:**
   - `Hash::check('DrillPass!2026', ...)` → `true`.
   - `App\Models\AuditLog::count()` → `2` (ten sam co na źródle); najnowszy wiersz odszyfrował się
     do dokładnie `{"guard":"web","remember":false,"migration_drill_marker":"MIGRATION-PROOF-2026-08-10-XYZ"}`
     — bajt w bajt to, co zapisano PRZED przeniesieniem.
   - `sha256sum` obu przywróconych plików (`storage-app-public`/`storage-app-private`) identyczne
     z sumami zapisanymi PRZED przeniesieniem. Kontener aplikacji (realny UID 1000) zapisał NOWY
     plik do przywróconego `storage-app-public` i odczytał go z powrotem — nie tylko odczyt.
   - `.env` docelowej: `HTTP_PORT_V4=127.0.0.1:18090`, `TRUSTED_PROXIES_CIDR=10.90.2.0/29`
     potwierdzone zgodne z `docker network inspect tenant-migdrill-edge` na TEJ maszynie — źródłowa
     miała `18080`/inną (choć numerycznie ten sam oktet `/29` przypadkiem, bo obie skanowały od
     tego samego wolnego punktu startowego w niezależnych piaskownicach — nie kolizja, niezależne
     obliczenie potwierdzone osobno na każdej).
   - `tenant-check.sh migdrill` na docelowej maszynie: **exit 0, cisza**.
   - Ponowny `apply` z tym samym `[hosts]`: `TENANT_HOSTS`/`APP_URL` niezmienione po ponownym
     uruchomieniu.
9. **Pułapka z 9.2 zreprodukowana wprost, celowo, jako negatywny test:** ten sam `apply`
   uruchomiony bez `[hosts]` — zakończył się `OK`, ale `.env` cicho wrócił do
   `migdrill.registrolabs.com` (domyślna wartość maszyny, `<slug>.APP_DOMAIN`), tracąc
   `migdrillmoved.registrolabs.com` bez żadnego ostrzeżenia. Naprawione z powrotem kolejnym `apply`
   z jawnym `[hosts]`.
10. Posprzątano: wszystkie kontenery/wolumeny/sieci obu symulowanych maszyn usunięte, throwaway
    obraz i tag `ghcr.io/patrykgielo/registro:v9.9.9` usunięte, cały katalog piaskownicy usunięty
    (przez pomocniczy kontener, bo część plików backupu restica jest root-owned — ten sam wzorzec
    co 8.5). `./vendor/bin/pint --test` (777 plików) i pełne `php artisan test` po zmianie:
    1 failed (`TenantFeatureTest`, niezwiązany, ten sam co baseline) / 5 skipped / 1068 passed —
    identyczne z baseline sprzed tej sesji.

## 9.7 — S8 dziś: mechanicznie gotowe, operacyjnie NIE, dopóki R2 nie jest domknięte

**Ta procedura odtwarza tenanta na nowej maszynie, JEŚLI masz kopię backupu poza maszyną, która
padła.** To duże "jeśli": repozytorium restica i jego hasło leżą dziś **na tej samej maszynie, którą
backupują** (`/opt/registro/tenant-backups/<slug>/`, patrz Część 6 i R2 w planie dwóch maszyn). Jeśli
ta maszyna umiera bez ŻADNEJ wcześniejszej ręcznej kopii `tenant-backups/<slug>/` gdzieś indziej —
**nie ma z czego odtwarzać**, ta procedura nie ma wejścia. S4 (klient ma czas, źródło żyje) działa
dziś bez zastrzeżeń. S8 (maszyna padła bez ostrzeżenia) działa wyłącznie dla tenantów, dla których
ktoś wcześniej ręcznie wyniósł `tenant-backups/<slug>/` poza tę maszynę (`rsync`/`scp` okresowy, poza
tym repo — nic tego dziś nie automatyzuje). Domknięcie: zdalny `RESTIC_REPOSITORY` i hasło poza
maszyną, dla każdego tenanta, zanim S8 stanie się czymś więcej niż teorią.

---

# CZĘŚĆ 10 — Postawienie drugiej maszyny, PreProd, od zera (Faza 4)

**Nic w tej części nigdy nie zostało uruchomione.** Maszyna PreProd nie istnieje — nie jest kupiona.
Fazy 1–3 planu dwóch maszyn (`~/.claude/plans/dwie-maszyny-uat-preprod.md`) są zmergowane i
zweryfikowane w piaskownicy (jedna domena na tenanta, `sync-certificate.sh` bez stacka legacy,
`NGINX_RELOAD_CONTAINER` zapisywane, backup obu wolumenów) — dzięki nim ta część jest wykonaniem
runbooka, nie odkrywaniem. To i tak nie znaczy, że pójdzie bezbłędnie: wzorzec z tego projektu
(`ci-cd-troubleshooting.md`) jest jednoznaczny — **każde pierwsze uruchomienie czegoś nowego
znajdowało pięć-sześć realnych błędów.** Traktuj to jak Część 1/2 kiedyś: znajdowanie błędów, nie
ceremonia.

**Różnica względem każdej innej części tego dokumentu:** wszystkie poprzednie części zakładają
maszynę, na której `/var/www/registro` już jest pełnym, działającym stackiem legacy (`docker-compose.
prod.yml` w górze, `.env` z sekretami). PreProd **nigdy nie ma** tego stacka w górze — `/var/www/
registro` istnieje tam wyłącznie jako katalog sterujący: coś, z czego `apply.sh` klonuje kod dla
tenantów i czyta dwie wartości (`APP_DOMAIN`, `CERT_DIR`). Pomylenie tych dwóch ról (uruchomienie
`deploy-init.sh` tutaj, "żeby było jak na UAT") postawiłoby drugi, nikomu niepotrzebny stack legacy,
z własną bazą, własnym certyfikatem i własnymi portami 80/443 walczącymi o te same porty co brzeg —
stąd ta część istnieje jako osobna, nie jako wariant Części 0/1.

## 10.0 — Rozmiar maszyny (decyzja zapisana raz, żeby nikt nie liczył od nowa)

Zmierzone na żywym stacku UAT: **jeden bezczynny tenant to ~1 GB** (horizon ~415 MB, mysql ~441 MB,
reszta poniżej 80 MB na kontener). Sufity ustawione w PR #157 (zabezpieczenie przed skokiem, nie
rezerwacja — Horizon legalnie sięga 10 workerów po 128 MB): **4,9 GB na stack** (app 1536 + mysql
1024 + horizon 1536 + redis 384 + nginx 256 + scheduler 256 = 4992 MB). Dzisiejsza maszyna: 2 vCPU /
7,8 GB, jeden stack.

**PreProd hostuje produkcję klientów — sizing pod sufit, nie pod średnią:**

| tenantów | bezczynnie (×~1,1 GB) | pod skokiem (×4,9 GB) | rekomendacja |
|---|---|---|---|
| 2 | ~2,2 GB | ~10 GB | **8 GB / 4 vCPU** — dwa naraz raczej nie skoczą jednocześnie |
| 5 | ~5,5 GB | ~25 GB | **16 GB / 4–6 vCPU** |

**Twardszym ograniczeniem będzie CPU, nie RAM.** Każdy stack niesie własny MySQL, Redis, pulę
PHP-FPM i mastera Horizona — to się nie dzieli między tenantami tak jak na maszynie współdzielonej.
Minimum na produkcję klientów: **4 rdzenie**. Dysk (dziś 100 GB) do policzenia osobno, gdy będą
znane realne rozmiary wolumenów ze zdjęciami sprzętu — rosną szybciej niż baza.

## 10.1 — `setup-production-server.sh` jest identyczny na obu maszynach

Cały skrypt (pakiety, swap, Docker, użytkownik `deploy`, `/opt/stacks`, cron `tenant-check`/
`sync-certificate`, SSH hardening, ufw) **nie różni się w niczym** między UAT a PreProd — obie
maszyny potrzebują dokładnie tej samej infrastruktury bazowej, żeby `apply.sh`/`tenant-check.sh`/
`sync-certificate.sh` mogły działać. Jedyna różnica leży w komunikacie na końcu skryptu: krok 3
rozgałęzia się na **3a** (stack legacy — dziś jedynie UAT) i **3b** (tylko control plane — PreProd).
Świadomie NIE flaga trybu: skrypt sam nie wie i nie musi wiedzieć, którą rolę ta maszyna dostanie —
to decyzja operatora podejmowana PO zakończeniu skryptu, przy tworzeniu `.env`, tak samo jak dziś.
Dodanie flagi dodałoby stan do zapamiętania bez żadnej korzyści — obie ścieżki i tak są jawnie opisane
w wydruku, każda z osobną, sprawdzalną komendą.

```bash
scp scripts/setup-production-server.sh scripts/server/deploy.sh root@<preprod-host>:/root/
ssh root@<preprod-host> 'bash /root/setup-production-server.sh'
```

Powinieneś zobaczyć ten sam ciąg `[+]` co przy pierwszym uruchomieniu na UAT, kończący się blokiem
"Remaining manual steps" z krokami 3a/3b poniżej.

## 10.2 — Sklonuj checkout sterujący (ścieżka 3b, bez stacka legacy)

```bash
ssh deploy@<preprod-host> 'git clone https://github.com/patrykgielo/registro.git /var/www/registro'
```

**Nie uruchamiaj tu `deploy-init.sh`.** Ten skrypt buduje i startuje `docker-compose.prod.yml`,
prosi o certyfikat dla TEGO stacka i zakłada bazę — dokładnie to, czego ta maszyna nie ma robić.

## 10.3 — Minimalny `.env` — dokładnie te klucze, żaden więcej

**Zweryfikowane czytaniem kodu, nie kopiowaniem `.env.production.example`:** `apply.sh` i
`sync-certificate.sh` razem czytają z tego pliku dokładnie cztery klucze, nic poza nimi.

| klucz | wymagany? | kto czyta | co się stanie bez niego |
|---|---|---|---|
| `APP_DOMAIN` | tak (gdy `apply.sh` wywołany bez `[hosts]` — czyli zawsze w rutynowej pracy) | `apply.sh:205`, `sync-certificate.sh:301` (opcjonalnie, sprawdzenie `www.`) | `apply.sh` odmawia natychmiast z jasnym komunikatem, zanim dotknie dysku |
| `CERT_DIR` | tak | `apply.sh:956`, `sync-certificate.sh:72` | oba skrypty `die()` natychmiast |
| `NGINX_RELOAD_CONTAINER` | **tak, na TEJ ścieżce** (opcjonalny formalnie, ale domyślna wartość jest tu błędna — patrz 10.6) | `sync-certificate.sh:86` | reload po pierwszym certyfikacie trafi w `registro-nginx`, kontener którego na tej maszynie NIGDY nie było — `die()` PO udanym wystawieniu certyfikatu |
| `MAIL_FROM_ADDRESS` | nie | `sync-certificate.sh:89` | pada z powrotem na `admin@${CERT_DIR}`. To adres, na który Let's Encrypt wysyła powiadomienia o zbliżającym się wygaśnięciu — **nie sprawdziliśmy, czy ta skrzynka w ogóle odbiera pocztę**. Nie blokuje wystawienia certyfikatu, ale traci się ostrzeżenie przed wygaśnięciem |

Każdy inny klucz z `.env.production.example` (`APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`,
`REDIS_PASSWORD`, `MAIL_*` poza `MAIL_FROM_ADDRESS`, `P24_*`, ...) należy do kontenerów, które ta
ścieżka nigdy nie startuje — nie twórz ich tutaj. `./scripts/validate-env.sh production` **nie jest
przeznaczony do tej ścieżki** — wymaga `APP_KEY`/`DB_PASSWORD`/`REDIS_PASSWORD` (patrz jego własne
`check_var_set`), a wypełnienie ich fikcyjnymi wartościami tylko po to, żeby przeszedł, zostawia
niepotrzebne sekrety leżące w pliku, którego nic nigdy nie przeczyta.

```bash
ssh deploy@<preprod-host> "cat > /var/www/registro/.env <<'ENV'
APP_DOMAIN=registroapps.com
CERT_DIR=registroapps.com
NGINX_RELOAD_CONTAINER=registro-edge-nginx
ENV
chmod 600 /var/www/registro/.env"
```

**`CERT_DIR=registroapps.com` jest wyborem operatora, nie odkryciem.** To wartość, którą przekażesz
certbotowi jako `--cert-name` (`sync-certificate.sh` robi to za Ciebie) — pinuje nazwę katalogu pod
`/etc/letsencrypt/live/`, tak samo jak `deploy-init.sh` robi to dla stacka legacy. Inaczej niż na
UAT, gdzie sama domena bazowa (`registrolabs.com`) jest jedną z nazw na certyfikacie (bo
`tenants:hostnames` ją tam wpisuje), **na tej ścieżce `registroapps.com` sam nigdy nie będzie SAN-em**
— certyfikat pokryje wyłącznie `<slug>.registroapps.com` per tenant (i `www.registroapps.com`, jeśli
kiedyś zacznie się rozwiązywać). To nie jest błąd: `--cert-name` to tylko etykieta linii certyfikatu,
nie musi być jedną z nazw, które pokrywa.

## 10.4 — Postaw brzeg pierwszy raz w historii tej maszyny

**Inaczej niż Część 1.** Część 1 to CUTOVER — zwalnia porty trzymane przez działający, stary nginx.
Tu nie ma żadnego nginxa do zwolnienia; port 80/443 nikt jeszcze nigdy na tej maszynie nie trzymał.
Nie ma więc ryzyka przerwy w działaniu czegokolwiek — jedyne co się dzieje, to pierwsze uruchomienie.

```bash
ssh deploy@<preprod-host> 'cd /var/www/registro && docker compose -f docker-compose.edge.yml up -d'
```

Powinieneś zobaczyć `registro-edge-nginx` w stanie `Up (healthy)` w ciągu ~30s (healthcheck co 10s,
3 próby). Odpowiada na razie tylko HTTP-em bootstrapowym (`edge.conf`) — `curl http://<ip-maszyny>/`
zwraca zamkniętym połączeniem (444, patrz `edge-stack.md` „default_server → 444"), co jest
oczekiwane: żaden tenant jeszcze nie jest podpięty.

## 10.5 — Sprowizjonuj pierwszego tenanta (dokładnie Część 2, bez zmian)

Ta sama procedura co na UAT — `apply.sh` nie wie i nie musi wiedzieć, że stoi na maszynie bez stacka
legacy; czyta `APP_DOMAIN` z tego samego pliku, którego istnienie 10.2–10.3 właśnie zapewniły.
Wykonaj Część 2.1–2.4, podmieniając **dwie różne rzeczy** — i to nie jest jedna zamiana:

- **nazwę hosta do SSH**: `srv1342834.hstgr.cloud` → `<preprod-host>` (dotyczy 2.2 i 2.3)
- **domenę aplikacji**: `registrolabs.com` → `registroapps.com` (dotyczy **2.4**)

Krok 2.4 to pułapka: jego komenda `curl` nie zawiera nazwy serwera w ogóle, tylko domenę
(`https://nazwaklienta.registrolabs.com/`). Mechaniczna podmiana samej nazwy hosta jej **nie
dotknie**, a wtedy sprawdzisz UAT zamiast maszyny, którą właśnie postawiłeś — i dostaniesz
zielony wynik z niewłaściwego pudła. Patrz słownik na początku dokumentu: nazwa serwera i domena
aplikacji to dwie różne rzeczy.

Krok 2.4
(sprawdzenie HTTPS) pokaże błąd certyfikatu **na dłużej niż zwykłe 15 minut** — do czasu, aż wykonasz
10.6 poniżej. Krok 2.5 (poczekaj na crona) **nie zadziała jeszcze automatycznie**: `sync-certificate.
sh`'s reload na tej maszynie, przed pierwszym certyfikatem, wymaga wartości z 10.3 — cron JĄ ma, ale
sam certyfikat i tak trzeba wymusić ręcznie raz, patrz niżej.

## 10.6 — Pierwszy certyfikat: wymuś ręcznie, nie czekaj na crona

> Cron (`/etc/cron.d/registro-certificate`, co 15 min) w końcu i tak by to zrobił — ale pierwszy raz
> warto zobaczyć wynik od razu, nie zgadywać z logu.

```bash
ssh root@<preprod-host> '/opt/registro/sync-certificate.sh; tail -30 /var/log/registro-certificate.log'
```

**Co powinieneś zobaczyć:** `No 'registro-app' container on this machine -- contributing zero legacy
hostnames (this machine never ran the legacy stack)`, potem nazwa hosta pierwszego tenanta
z `/opt/stacks/<slug>/.env`, `Requesting certificate
for 1 name(s)...`, i na końcu `Certificate now covers 1 name(s); nginx reloaded`. Ten ostatni reload
trafia w `registro-edge-nginx` (10.3's `NGINX_RELOAD_CONTAINER`) — **gdyby ten klucz nie był
ustawiony**, dokładnie w tym miejscu zobaczyłbyś `die()` na `nginx rejected its configuration after
renewal` albo `No such container: registro-nginx`, MIMO że certyfikat sam już poprawnie powstał na
dysku (`certbot certificates` by to potwierdził) — myląca porażka po faktycznym sukcesie, dokładnie
dlatego 10.3 wymaga tej wartości z góry, a nie liczy na to, że `apply.sh` dopisze ją później (dopisze
— ale dopiero po 10.7, i tylko re-potwierdzi tę samą wartość).

## 10.7 — Przełącz brzeg na TLS

Odpowiednik Części 1, kroki 1.0/1.3/1.4 — bez kroku 1.2 (nie ma starego nginxa do zatrzymania) i bez
1.1 jako osobnego kroku (możesz go i tak wykonać jako czystą weryfikację przed 1.3, zero ryzyka).

```bash
ssh deploy@<preprod-host> 'cd /var/www/registro && \
  CERT=$(grep -m1 "^CERT_DIR=" .env | cut -d= -f2-) && \
  sed "s|CERT_DOMAIN|$CERT|g" docker/nginx/edge/edge-tls.conf > docker/nginx/edge/edge-tls.local.conf && \
  EDGE_NGINX_CONF=edge-tls.local.conf docker compose -f docker-compose.edge.yml up -d edge-nginx'
```

```bash
curl -s -o /dev/null -w "HTTP %{http_code} tls=%{ssl_verify_result}\n" https://<slug>.registroapps.com/up
```

Musisz zobaczyć `HTTP 200 tls=0`. **Odwrót, jeśli nie:** `EDGE_NGINX_CONF=edge.conf docker compose -f
docker-compose.edge.yml up -d edge-nginx` — wraca do bootstrapu HTTP. Nic tu nie miało wcześniej
działającego stanu do utraty (10.4), więc to jest strzał bez presji czasu, inaczej niż Część 1.2–1.5.

## 10.8 — Domknij pętlę: `apply.sh` potwierdza TLS

```bash
ssh -t deploy@<preprod-host> '/opt/registro/apply.sh <slug> v0.13.0-rc9 --foreground'
```

W logu szukaj: `Edge is terminating TLS (edge-tls.local.conf) -- NGINX_RELOAD_CONTAINER set to
registro-edge-nginx`. To jest re-potwierdzenie tej samej wartości, którą 10.3 już wpisało ręcznie —
idempotentne, nie duplikuje linii w `.env`.

## 10.9 — DNS: dopiero teraz, nie wcześniej

Wskaż `*.registroapps.com` na tę maszynę. **Bezpieczne dopiero teraz** dzięki Fazie 1 (już
zmergowanej): każdy tenant dostaje wyłącznie własną domenę maszyny w `TENANT_HOSTS`/`server_name` —
przed tą naprawą wskazanie drugiej domeny na drugą maszynę zrywałoby odnawianie certyfikatu na
WSZYSTKICH tenantach UAT naraz (patrz `ci-cd-troubleshooting.md`, incydent „`apply.sh` domyślnie
doklejał OBIE domeny").

## 10.10 — Weryfikacja końcowa

1. `ssh root@<preprod-host> '/opt/registro/tenant-check.sh'` — musi być cicho (exit 0, zero stdout).
2. `certbot certificates --cert-name registroapps.com` na maszynie — nazwy pokrywają dokładnie tych
   tenantów, którzy istnieją, nic więcej.
3. `/opt/registro/sync-certificate.sh` ponownie, ręcznie — `Certificate already covers N name(s) --
   nothing to do`, exit 0. Dowód, że przechodzi **bez stacka legacy**, nie tylko raz po prowizji.
4. Pełna ścieżka klienta (rezerwacja, płatność, potwierdzenie, wydanie, zwrot) na pierwszym
   tenancie PreProd — patrz plan, sekcja 8, punkt 6. To NIE jest część tej sesji.

## 10.11 — Co pozostaje niezweryfikowane, dopóki ta maszyna nie istnieje

Zapisane wprost, bo to jest dokładnie to, czego ta część nie może dowieść bez prawdziwego hosta:
rzeczywisty zakup i dostawa maszyny; realne DNS `*.registroapps.com` wskazujące na jej IP; realny
`certbot` przeciw prawdziwemu Let's Encrypt (nie stagingowi, nie piaskownicy) z tej maszyny;
rzeczywisty ruch przez `ufw`/`ufw-docker` na tym hoście; i to, czy sekwencja 10.4–10.8 faktycznie
znajdzie swoje pięć-sześć błędów, jak każde inne pierwsze uruchomienie w tym projekcie. Traktuj tę
część jako dobrze uzasadnioną hipotezę, nie jako dowód.

---

## Dokumenty szczegółowe

| temat | plik |
|---|---|
| Jak działa `apply`, `check`, backup | `tenant-apply.md` |
| Brzeg, sieci, pełna procedura przełączenia | `edge-stack.md` |
| Compose per tenant, prefiksy, twardnienie | `tenant-compose-stack.md` |
| Komenda `registro:tenant-provision` | `../features/tenant-stack-provisioning.md` |
| Przenosiny domeny i ich pułapki | `domain-migration-registrolabs.md` |
| `stage_volume()` entrypoint bug (Część 9.6), `sync-certificate.sh` fixes | `tenant-apply.md`, `edge-stack.md` |
| Postawienie drugiej maszyny (PreProd) od zera, sizing | Część 10 (ten dokument) |

---

**Historia dokumentu**

| data | co |
|---|---|
| 2026-08-09 | Utworzony. Części 1 i 2 niewykonane na serwerze. |
| 2026-08-09 | Krok 4.1 zastąpiony realną komendą (`registro:password-setup-link`, nowa, przetestowana). Dodano Część 7 (usunięcie klienta) i Część 8 (przywracanie z backupu, przetestowane end-to-end). |
| 2026-08-10 | Faza 2 planu dwóch maszyn: `tenant-backup.sh`/`apply.sh` obejmują teraz `storage-app-public`/`storage-app-private` w tym samym snapshocie co bazę (7.6, 7.8, 8.1, nowa Część 8.6/8.7, przetestowane end-to-end). Krok 7.5 zaktualizowany — zatrzymany kontener dedykowanego stacka już nie zamraża certyfikatu dla innych tenantów (`sync-certificate.sh` czyta `TENANT_HOSTS` z `.env`, nie z żywego kontenera). |
| 2026-08-10 | Faza 3 planu dwóch maszyn: nowa Część 9 — przeniesienie klienta między maszynami / zmiana domeny (S3/S4/S8), przetestowana end-to-end (9.6). Przy okazji znaleziony i naprawiony realny bug: `stage_volume()` w `apply.sh`/`tenant-backup.sh` nigdy nie ustawiały `--entrypoint`, więc backup obu wolumenów storage tworzył pusty snapshot na prawdziwym obrazie (zgłaszane jako DEGRADED, łatwe do przeoczenia, bo zrzut bazy szedł poprawnie) (ten obraz ma własny entrypoint odmawiający startu jako root). |
| 2026-08-10 | Faza 4 planu dwóch maszyn: nowa Część 10 — postawienie maszyny PreProd od zera, legacy-free (checkout jako katalog sterujący, minimalny `.env`, pierwszy certyfikat i przełączenie brzegu bez cutoveru, bo nie ma starego nginxa do zwolnienia), sizing zapisany jako decyzja. `setup-production-server.sh`'s domykający wydruk rozdzielony na ścieżki 3a (legacy)/3b (control-plane-only). **Nic z Części 10 nigdy nie zostało uruchomione** — maszyna nie istnieje. |
