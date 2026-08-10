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

`sync-certificate.sh` enumeruje dziś zarówno starą, współdzieloną bazę, JAK i każdy dedykowany
stack pod `/opt/stacks/*/` (czyta `TENANT_HOSTS` bezpośrednio z kontenera `app` tego stacka —
dokładnie tę samą listę, którą `apply.sh` już zapisał w jego `.env`). Nowy klient dostanie się na
certyfikat przy najbliższym przebiegu crona (`/etc/cron.d/registro-certificate`, co 15 minut) —
bez żadnego ręcznego kroku. Do tego czasu `curl` z kroku 2.4 pokaże błąd certyfikatu — to
oczekiwane, poczekaj do 15 minut i powtórz sprawdzenie; oczekuj `HTTP 200 tls=0`.

**Jeśli po 15+ minutach certyfikat WCIĄŻ nie pokrywa nowego klienta** — to nie jest już "znana
luka", tylko awaria: sprawdź log crona (`/var/log/registro-certificate.log` na serwerze). Skrypt
teraz **przerywa cały przebieg** (i zostawia certyfikat bez zmian), jeśli którykolwiek dedykowany
stack istnieje, ale nie odpowiada (kontener padnięty, zepsuty `.env`, timeout) — więc brak
aktualizacji zwykle oznacza, że TEN klient (albo inny stack na tym samym serwerze) jest w takim
stanie, nie że mechanizm enumeracji znów widzi tylko starą bazę.

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
**z obecnym plikiem `docker-compose.prod.yml`** próbuje odpytać jego kontener `app` o `TENANT_HOSTS`.
Jeśli plik istnieje, ale kontener nie odpowiada (bo już go zatrzymałeś, a katalog jeszcze zostaje) —
skrypt **przerywa CAŁY przebieg** i **nie dotyka certyfikatu wcale**, dla żadnego klienta, dopóki
sytuacja się nie wyjaśni. To jest zamierzony fail-safe (patrz krok 2.5), ale oznacza, że pozostawienie
zatrzymanych kontenerów z wciąż obecnym katalogiem stacka jest stanem, którego trzeba unikać, nie
tylko posprzątać później.

**Zasada:** krok 7.6 (zatrzymanie kontenerów + usunięcie katalogu) rób jako jedną, nieprzerywaną
sekwencję poleceń — nie zostawiaj przerwy między „kontenery stoją" a „katalog zniknął". Jeśli mimo to
`sync-certificate.sh` trafi dokładnie w to okno i przerwie się raz — to się samo naprawi przy
następnym przebiegu (15 minut później), bo katalog już nie będzie istniał. Sprawdź log
(`/var/log/registro-certificate.log`) jeśli chcesz się upewnić.

## 7.6 — NIEODWRACALNE: zdejmij stack z serwera

**Punkt bez odwrotu.** Poniższe usuwa kontenery, sieć i **wolumeny** (razem z bazą danych tego
klienta) na stałe. Zweryfikuj PRZED uruchomieniem:
- [ ] Krok 7.3 (ostatni backup) zakończył się sukcesem i snapshot istnieje w `restic snapshots`.
- [ ] Krok 7.4 (odłączenie od brzegu) zakończony — domena już nie routuje do tego stacka.
- [ ] Masz nazwę sluga poprawnie wpisaną — to polecenie nie pyta o potwierdzenie po nazwie.

> **Jeśli klient miał wgrane zdjęcia/pliki (`storage-app-public`, `storage-app-private`) i zależy Ci
> na ich zachowaniu — zrób to TERAZ, osobno.** `tenant-backup.sh` kopiuje wyłącznie zrzut bazy danych
> (`mysqldump`), nigdy te wolumeny — to jest nazwana, nie rozwiązana tu luka (patrz „Czego ten
> dokument jeszcze nie umie"). Przykład ręcznej kopii jednego wolumenu:

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
| Pliki klienta (zdjęcia itp.) | Tylko jeśli zrobiłeś ręczną kopię w kroku 7.6 | Brak automatyzmu | `tenant-backup.sh` ich nie obejmuje w ogóle |

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

`tenant-backup.sh`/`apply.sh` backupują **wyłącznie jeden plik: zrzut `mysqldump` całej bazy tego
klienta.** Restic go kompresuje, deduplikuje i przechowuje pod `/opt/registro/tenant-backups/<slug>/repo`.
To, co wraca z restica, to **plik `.sql`, nie działająca baza danych** — trzeba go jeszcze wczytać do
MySQL (krok 8.4). Pliki użytkownika (`storage-app-public`, `storage-app-private`) **nie są objęte
tym mechanizmem w ogóle** — patrz 7.8.

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

## 8.3 — Przywrócenie pliku (jedyny plik w tym repo = "cały" backup)

> `restic dump` strumieniuje zawartość pliku z backupu prosto na stdout — nie trzeba nic
> rozpakowywać na dysk osobno. Działa identycznie dla `latest` i dla konkretnego ID snapshota.

> Krok 1: znajdź dokładną ścieżkę pliku wewnątrz snapshota — to plik tymczasowy z `mktemp`
> (`tenant-backup.sh`/`apply.sh` obie go tak tworzą), więc nazwa jest za każdym razem inna.

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
  (patrz 8.5). Backup obejmuje wyłącznie zrzut bazy danych — pliki klienta
  (`storage-app-public`/`storage-app-private`) nie są nim objęte, patrz 7.6/7.8.
- **Poczta wychodzi z prywatnego adresu Gmail.** Klient zobaczy `Registro <patryk3580@gmail.com>`.
  Do naprawienia przy pierwszym prawdziwym kliencie.

---

## Dokumenty szczegółowe

| temat | plik |
|---|---|
| Jak działa `apply`, `check`, backup | `tenant-apply.md` |
| Brzeg, sieci, pełna procedura przełączenia | `edge-stack.md` |
| Compose per tenant, prefiksy, twardnienie | `tenant-compose-stack.md` |
| Komenda `registro:tenant-provision` | `../features/tenant-stack-provisioning.md` |
| Przenosiny domeny i ich pułapki | `domain-migration-registrolabs.md` |

---

**Historia dokumentu**

| data | co |
|---|---|
| 2026-08-09 | Utworzony. Części 1 i 2 niewykonane na serwerze. |
| 2026-08-09 | Krok 4.1 zastąpiony realną komendą (`registro:password-setup-link`, nowa, przetestowana). Dodano Część 7 (usunięcie klienta) i Część 8 (przywracanie z backupu, przetestowane end-to-end). |
