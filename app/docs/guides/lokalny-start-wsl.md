# Lokalny start Registro na Windows (WSL2) — jedna komenda

Dla kogo: właściciel maszyny z Windows 11, na której Registro działa lokalnie w WSL2
(`Ubuntu-24.04`, Docker Engine w WSL, nie Docker Desktop). Po restarcie Windows nic nie działa,
dopóki ktoś nie wystartuje WSL — ta strona to jedyna, stała procedura.

## Jedna komenda

W PowerShellu (Windows PowerShell 5.1 wystarczy):

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "\\wsl.localhost\Ubuntu-24.04\var\www\projects\registro\app\scripts\local\start-wsl.ps1"
```

`-ExecutionPolicy Bypass` jest potrzebne, bo skrypt leży na ścieżce UNC `\\wsl.localhost` — bez tego
polityka wykonywania potrafi go odrzucić. Ścieżka musi zostać dokładnie taka (dwa backslashe na początku).

Na końcu zobaczysz `RESULT: OK` i dwa adresy, albo `RESULT: FAILED (exit N)` z powodem wyżej.

| Adres | Co to |
|---|---|
| `https://firma.registro.local:8444/admin/login` | tenant `firma` (panel) |
| `https://registro.local:8444` | strona centralna |

Samo sprawdzenie, bez zmian (nic nie startuje, nic nie kasuje):

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "\\wsl.localhost\Ubuntu-24.04\var\www\projects\registro\app\scripts\local\start-wsl.ps1" -Status
```

Z terminala Ubuntu (WSL już działa) wystarczy sama strona WSL:

```bash
cd /var/www/projects/registro/app && bash scripts/local/up.sh          # podnieś i poczekaj
cd /var/www/projects/registro/app && bash scripts/local/up.sh --status # tylko raport
```

Flagi `up.sh`: `--tenant <slug>` (domyślnie `firma`), `--timeout <s>` (domyślnie 120). Skrypt PowerShell
przyjmuje `-Tenant`, `-TimeoutSec`, `-Distro`, `-ProjectPath`.

> Uruchamiasz z Git Basha (np. z Claude Code)? Bash zjada backslashe: ścieżkę UNC podaj z **czterema**
> na początku (`'\\\\wsl.localhost\...'`). W samym PowerShellu — dwa, jak wyżej.

## Co robi (po kolei)

**`start-wsl.ps1` (strona Windows):**

1. Sprawdza, czy działa ukryty „keepalive" (`wsl.exe -d Ubuntu-24.04 -- sleep infinity`). Jeśli nie — startuje
   jeden, ukryty. Rozpoznaje go po **dokładnej** linii poleceń z `Win32_Process`; innych procesów `wsl.exe`
   nie rusza i **nigdy niczego nie zabija**. `wsl.exe` sam tworzy proces potomny z identyczną linią poleceń,
   więc jeden keepalive to dwa pasujące procesy (rodzic + dziecko) — liczy się tylko **korzeń** (taki, którego
   rodzic nie pasuje do wzorca); raportowany jest PID korzenia.
2. Czeka, aż dystrybucja odpowie.
3. Wywołuje `scripts/local/up.sh` w WSL i pokazuje jego wynik.
4. Sprawdza plik `hosts` Windows i ostrzega, jeśli brakuje `registro.local` albo `<tenant>.registro.local`
   (naprawa: `C:\projects\registro\.local-setup\add-hosts.ps1 <slug>`, jako administrator).

**`up.sh` (strona WSL), idempotentny:**

1. Docker aktywny? Jeśli nie — `sudo -n systemctl start docker` (bez pytania o hasło; jeśli sudo chce hasła,
   skrypt kończy się kodem 10 i podaje dokładną komendę do ręcznego wykonania).
2. Certyfikaty mkcert w `docker/ssl/` obecne i niewygasłe (przed startem, bo bez nich nginx nie wstanie).
3. `docker compose up -d --no-build --pull never` z **jawną listą serwisów** = `docker compose config --services`
   (tylko stdout; ostrzeżenia compose'a ze stderr nie trafiają na listę) bez `node`.
   Lista jest jawna, bo goły `up -d` wystartowałby też `node` (serwer Vite — tylko do `npm run dev` i buildów).
   `--no-build --pull never`: brak obrazu = czytelny błąd (kod 12), nie kilkuminutowy build ani wiszące
   pobieranie z rejestru bez internetu.
4. Kasuje `public/hot` **tylko** gdy ma pewność, że `node` nie działa (patrz niżej).
5. Odpytuje `https://<tenant>.registro.local:8444/admin/login` (`curl -k --resolve ...:127.0.0.1`, więc
   bez wpisów w `hosts` wewnątrz WSL) aż do HTTP 200, max 120 s.
6. Końcowa kontrola: wszystkie serwisy `running`, żaden `restarting` / `unhealthy`, login = 200 — na
   **dwóch kolejnych próbkach** (jedna mogłaby złapać kontener w pętli restartów w chwili `running`;
   `--status` robi tak samo, więc trwa o jedną przerwę, ok. 2 s, dłużej). Wypisuje serwisy, adresy, gałąź.

Czego **nigdy** nie robi: migracji, seederów, niczego, co pisze do bazy; `down`, `down -v`,
`--force-recreate`, `--build`, pobierania obrazów (`--pull never`). Uruchomiony na działającym stosie nie zmienia żadnego kontenera
(sprawdzone: ID i `StartedAt` wszystkich kontenerów identyczne przed i po).

## Dlaczego keepalive

WSL2 zamyka dystrybucję, gdy nie ma żadnego procesu `wsl.exe` po stronie Windows. W długich sesjach
projekt przestawał działać w trakcie bezczynności. `sleep infinity` pod ukrytym `wsl.exe` trzyma
dystrybucję przy życiu. Komunikat „N keepalives exist" pojawia się tylko przy więcej niż jednym **korzeniu**
(rodzic + jego proces potomny to jeden keepalive). Kilka korzeni jest nieszkodliwe (wystarczy jeden);
skrypt nie dokłada kolejnego i nie kasuje nadmiarowych. Chcesz zatrzymać keepalive świadomie:

```powershell
Get-CimInstance Win32_Process -Filter "Name='wsl.exe'" | Where-Object { $_.CommandLine -match '-- sleep infinity' } |
  Format-Table ProcessId, ParentProcessId   # korzeń = ten, którego ParentProcessId nie jest na liście
Stop-Process -Id <PID korzenia>
```

## Dlaczego 502 przez kilka sekund po starcie

Gdy WSL wstaje, Docker i kontenery podnoszą się same (`restart: unless-stopped`). nginx jest gotowy
szybciej niż php-fpm w kontenerze `app` — jego entrypoint najpierw czeka na MySQL. W tym oknie nginx
odpowiada **502**. To normalne; `up.sh` traktuje 000 i 5xx jako „jeszcze nie" i czeka do 200. Pierwsze
żądanie po zimnym starcie bywa wolne (opcache) — stąd limit 15 s na pojedyncze zapytanie.
HTTP inne niż 200 i 5xx (np. 404) kończy się kodem 14 po **trzech kolejnych próbkach** (ok. 6 s): aplikacja
odpowiedziała, czekanie nie pomoże — zwykle zły `--tenant`.

## `public/hot`

Zalegający plik `public/hot` (po `npm run dev`) każe stronie ładować assety z martwego serwera Vite
(brak stylów). `up.sh` usuwa go **tylko**, gdy kontener `node` jest `exited` / `created` / nie istnieje.
Gdy `node` działa — plik zostaje (to tryb dev). Gdy nie da się zapytać Dockera — zostaje (brak informacji
to nie „node nie działa"). W trybie `--status` zalegający plik jest raportowany (kod 16), nie kasowany.

## Kody wyjścia i rozwiązywanie problemów

| Kod | Znaczenie | Co robić |
|---|---|---|
| 0 | stos działa, login = 200 | — |
| 10 | Docker nieużywalny | `sudo systemctl start docker` (ręcznie, jeśli sudo chce hasła); gdy demon aktywny, a `docker info` nie działa — sprawdź grupę `docker` użytkownika |
| 11 | brak/pusty/wygasły certyfikat w `docker/ssl/` | wygeneruj mkcert dla `registro.local` i `*.registro.local` (`docker/ssl/README.md`) |
| 12 | `docker compose` zawiódł (config / up / brak obrazu) | czytaj komunikat; brak obrazu: `docker compose build` ręcznie |
| 13 | serwis padł / `restarting` / `unhealthy` | `docker compose logs --tail 50 <serwis>` — skrypt wypisuje, który |
| 14 | timeout albo aplikacja zwraca coś innego niż 200 | `docker compose logs --tail 50 app`; zły tenant → `--tenant` |
| 15 | to nie drzewo Registro / brak `curl` | uruchom z `/var/www/projects/registro/app` |
| 16 | `public/hot` zalega i nie da się go usunąć (lub `--status`: zalega) | `rm public/hot` |
| 20–24 | strona Windows: brak `wsl.exe` / dystrybucji / nie wystartował keepalive / dystrybucja nie odpowiada / autostart | komunikat w konsoli |

Znane ograniczenie: dwie kolejne próbki (co ok. 2 s) łapią typową pętlę restartów, ale kontener o dłuższym
cyklu może mimo to przejść niezauważony. Gdy coś „działa, ale dziwnie": `docker compose ps` i
`docker compose logs`.

## Autostart przy logowaniu (opcjonalny)

Domyślnie **wyłączony** — nic nie jest instalowane, dopóki sam tego nie zrobisz.

```powershell
# zobacz, co zostałoby zarejestrowane (nic nie robi):
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "\\wsl.localhost\Ubuntu-24.04\var\www\projects\registro\app\scripts\local\start-wsl.ps1" -InstallAutostart -WhatIf
# zainstaluj:
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "\\wsl.localhost\Ubuntu-24.04\var\www\projects\registro\app\scripts\local\start-wsl.ps1" -InstallAutostart
# usuń:
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "\\wsl.localhost\Ubuntu-24.04\var\www\projects\registro\app\scripts\local\start-wsl.ps1" -RemoveAutostart
```

Rejestruje Scheduled Task `Registro Local Start` (bieżący użytkownik, przy logowaniu, bez praw
administratora, ukryte okno, bez limitu czasu — akcja zadania i tak kończy się po ok. 2 minutach, więc limit
72 h nie jest tu istotny). Skrypt leży na `\\wsl.localhost`, który przy logowaniu jeszcze nie istnieje, więc akcja zadania **najpierw**
startuje dystrybucję (`wsl -- true`), czeka na ścieżkę i dopiero wtedy odpala skrypt. Wynik ostatniego
przebiegu: `%LOCALAPPDATA%\registro-local-start.log`. Gdy rejestracja zgłosi „Access is denied" — powtórz
z PowerShella uruchomionego jako administrator.

**Zweryfikowane 2026-11-22** (Windows 11 Home 10.0.26300, autostart zarejestrowany na życzenie
właściciela): po `wsl.exe --shutdown` i `Start-ScheduledTask` log skończył się `RESULT: OK` po **27 s**
(7/7 serwisów, panel tenanta HTTP 200). Zadanie wróciło do `Ready`, a ukryty keepalive (`wsl.exe -d
Ubuntu-24.04 -- sleep infinity`, rodzic + dziecko) **przeżył koniec zadania** — Harmonogram zadań nie
zamyka drzewa procesów. Po 5 minutach bezczynności (nic nie dotykało WSL) keepalive nadal żył, a
dystrybucja była `Running`.

Jak to powtórzyć (np. po aktualizacji Windows/WSL). Zaczynamy od zera, żeby istniejący keepalive nie dał
fałszywego „OK":

```powershell
wsl.exe --shutdown                                   # zatrzymuje dystrybucję, keepalive i kontenery
Start-ScheduledTask -TaskName 'Registro Local Start' # to samo, co zrobi logowanie
# po ok. 30 s – 2 min:
Get-Content "$env:LOCALAPPDATA\registro-local-start.log"   # musi kończyć się: RESULT: OK
Get-ScheduledTask -TaskName 'Registro Local Start' | Select-Object State   # Ready = akcja zadania skończona
# keepalive i stan dystrybucji — sprawdzane WYŁĄCZNIE od strony Windows:
Get-CimInstance Win32_Process -Filter "Name='wsl.exe'" | Select-Object ProcessId,ParentProcessId,CommandLine
wsl.exe -l -v
```

Poprawny wynik: dwa procesy `wsl.exe ... -- sleep infinity` i `Ubuntu-24.04  Running`. Powtórz ostatnie
dwie komendy po kilku minutach bezczynności. **Nie sprawdzaj tego przez `-Status`** ani nic, co czyta
`\\wsl.localhost` — samo otwarcie tej ścieżki budzi dystrybucję, więc zamaskuje to, że się zatrzymała.
Brak procesu `sleep infinity` albo `Stopped` = keepalive nie przeżył zadania: usuń autostart
(`-RemoveAutostart`) i zgłoś to.

## Testy

`bash tests/shell/run.sh` — przypadki `50_local_up_pure_logic.sh` (diff serwisów, wykluczenie `node`,
decyzja o `public/hot`, kody HTTP, argumenty) i `51_local_up_flow_stubbed_docker.sh` (prawdziwy `up.sh`
z atrapami `docker`/`curl`/`systemctl`/`sudo`: `--status` nie zmienia niczego, `node` nie trafia do
`compose up`, każdy kod wyjścia).
