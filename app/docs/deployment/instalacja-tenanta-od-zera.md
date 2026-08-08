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

Slug to nazwa techniczna klienta, widoczna w adresie: `<slug>.registrolabs.com`.

**Slug trafia do publicznych logów Certificate Transparency.** Każdy może je przeglądać. Jeśli
klient nie chce, żeby świat wiedział, że z Ciebie korzysta — użyj neutralnego sluga.

Dozwolone: małe litery, cyfry, myślnik. Bez polskich znaków, bez kropek.

### 2.2 — Uruchom instalację

> Jedna komenda instaluje całego klienta. `--foreground` sprawia, że **widzisz to na żywo** —
> a o to Ci chodzi przy pierwszym razie. Bez tej flagi proces odczepia się od terminala.

```bash
ssh -t deploy@srv1342834.hstgr.cloud '/opt/registro/apply.sh nazwaklienta v0.13.0-rc9 \
  nazwaklienta.registrolabs.com \
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

### 2.5 — Dołóż subdomenę klienta do certyfikatu RĘCZNIE

**Nie czekaj na crona. On tego nie zrobi — a co gorsza, cofnie to, co zrobisz ręcznie.**

`sync-certificate.sh` pyta o listę nazw **bazę starego, współdzielonego stacka**. Klient na własnym
stacku ma własną bazę, więc jego nazwa nigdy tam nie trafi. I ponieważ skrypt wystawia certyfikat
na **dokładnie tę listę**, każda nazwa spoza niej zostaje **usunięta** przy najbliższym przebiegu.

Czyli: dodasz nazwę ręcznie, sprawdzisz że działa, pójdziesz spać — i w ciągu 15 minut certyfikat
znowu jej nie pokrywa, bez żadnego komunikatu. To znana, zapisana luka (`edge-stack.md`, sekcja
„Known gap"), nie awaria — ale musisz ją obejść.

> Krok 0: **zatrzymaj crona uzgadniającego nazwy**, zanim cokolwiek dodasz.
>
> Kosztuje Cię to mniej, niż się wydaje: odnowieniami certyfikatu zajmuje się **własny timer
> certbota** (`certbot.timer`, aktywny i niezależny). Wstrzymujesz wyłącznie automatyczne
> dokładanie nazw dla tenantów ze starego stacka — a tych nie przybywa.

```bash
ssh root@srv1342834.hstgr.cloud 'mv /etc/cron.d/registro-certificate /root/registro-certificate.wstrzymany && echo "cron wstrzymany"'
```

Przywrócisz go dopiero, gdy `sync-certificate.sh` nauczy się widzieć stacki dedykowane. Zapisz
sobie to gdzieś — bo inaczej za pół roku nikt nie będzie wiedział, czemu ten plik leży w `/root`.

> Krok 1: odczytaj, jakie nazwy certyfikat pokrywa dzisiaj.

```bash
ssh root@srv1342834.hstgr.cloud 'certbot certificates --cert-name registrolabs.com | grep Domains'
```

> Krok 2: wystaw ponownie, wypisując **WSZYSTKIE** nazwy z kroku 1 **plus** nową. Pominięcie
> którejkolwiek dotychczasowej usunie ją z certyfikatu i zepsuje tamten adres.

```bash
ssh root@srv1342834.hstgr.cloud 'certbot certonly --webroot -w /var/www/letsencrypt \
  --cert-name registrolabs.com --expand \
  -d registrolabs.com -d www.registrolabs.com -d budowlana.registrolabs.com \
  -d nazwaklienta.registrolabs.com \
  --non-interactive'
```

> Krok 3: brzeg musi przeczytać nowy certyfikat.

```bash
ssh deploy@srv1342834.hstgr.cloud 'docker exec registro-edge-nginx nginx -s reload'
```

Potem powtórz sprawdzenie z 2.4 — teraz musi być `HTTP 200 tls=0`.

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

Link jest **ważny 24 godziny**. Jeśli przepadnie:

> Generuje nowy link dla właściciela.

```bash
ssh deploy@srv1342834.hstgr.cloud 'cd /opt/stacks/nazwaklienta && \
  docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="..."'
```

*(Uzupełnić przy pierwszym realnym użyciu — komenda nie była jeszcze uruchamiana.)*

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

# Czego ten dokument jeszcze nie umie

Uczciwa lista, żeby nie zaskoczyła Cię w trakcie:

- **Części 1 i 2 nigdy nie były wykonane na serwerze.** Spodziewaj się poprawek.
- **Krok 4.1** (nowy link do hasła) nie ma jeszcze sprawdzonej komendy.
- **Certyfikat dla nowego klienta trzeba dołożyć ręcznie, a crona uzgadniającego nazwy trzeba
  najpierw wstrzymać** (krok 2.5) — bo inaczej cofnie tę zmianę w ciągu 15 minut. To najbrzydszy
  element całej tej ścieżki. Domknięcie: `sync-certificate.sh` musi nauczyć się enumerować
  podpięte stacki dedykowane, zamiast pytać wyłącznie bazę starego. Zapisane jako follow-up
  w `edge-stack.md`. **Dopóki to nie powstanie, każdy nowy klient wymaga ręcznego kroku 2.5.**
- **Nie ma tu usuwania klienta.** Offboarding istnieje w aplikacji, ale nie ma ścieżki operatora
  „zdejmij ten stack z serwera".
- **Nie ma tu przywracania z backupu.** Skrypt robi kopie; procedura odtworzenia nie jest opisana
  ani przetestowana.
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
