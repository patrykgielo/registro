# Polityka retencji i ochrony danych (RODO / prawo polskie)

> **Status dokumentu:** roboczy (v1, 2026-06-30). Wymaga przeglądu i akceptacji DPO przed
> traktowaniem jako oficjalna polityka. Część zapisów opisuje stan **zaimplementowany** w kodzie,
> część — **dług/plan** (oznaczone wyraźnie).
>
> **Zakres:** Registro — wielodostępowa (multi-tenant) platforma SaaS do rezerwacji wizyt,
> wypożyczalni sprzętu i zamówień. Dokument łączy podstawy prawne (RODO + prawo polskie)
> z faktyczną implementacją egzekwowaną w kodzie.
>
> **Powiązane dokumenty:**
> - `app/docs/legal/analytics-gdpr-lia.md` — Legitimate Interest Assessment dla analityki (Art. 6(1)(f))
> - `app/docs/features/tenant-lifecycle.md` — cykl życia tenanta (Faza 5)
> - `.claude/rules/migrations.md` — polityka FK onDelete (Faza 5.2)

---

## Legenda statusu

| Znacznik | Znaczenie |
|----------|-----------|
| ✅ **ZAIMPLEMENTOWANE** | Egzekwowane w kodzie / konfiguracji, zweryfikowane w tym dokumencie |
| 🟡 **CZĘŚCIOWE** | Fundament istnieje, ale brakuje pełnej automatyzacji / części scenariuszy |
| 🔴 **DŁUG / PLAN** | Niezaimplementowane — decyzja lub praca do wykonania |

---

## 1. Role RODO (Art. 28)

### 1.1 Podział ról

| Podmiot | Rola RODO | Uzasadnienie |
|---------|-----------|--------------|
| **Tenant** (organizacja korzystająca z Registro) | **Administrator danych** (data controller) | To tenant decyduje o celach i sposobach przetwarzania danych swoich klientów (rezerwacje, wypożyczenia, zamówienia). |
| **Registro** (operator platformy) | **Podmiot przetwarzający** (procesor, data processor) | Registro przetwarza dane osobowe klientów tenanta **wyłącznie** w imieniu i na polecenie tenanta, świadcząc usługę SaaS. |
| **Klient końcowy** tenanta (osoba fizyczna rezerwująca/wypożyczająca) | **Podmiot danych** (data subject) | Osoba, której dane dotyczą. |

> **Uwaga o danych konta tenanta:** w odniesieniu do danych właściciela/pracowników tenanta
> (konto `users`, dane rozliczeniowe SaaS `tenant_payments`) Registro jest **administratorem**
> (relacja B2B: Registro ↔ klient SaaS). Te dwie role współistnieją i nie należy ich mylić.

### 1.2 Zakres przetwarzania (procesor)

Registro przetwarza w imieniu tenanta dane osobowe zawarte w:
- `orders` (zamówienia — B2C i B2B), `appointments` (wizyty), `rentals` (wypożyczenia)
- `payments` (płatności / webhooki P24)
- `carts` (koszyki — efemeryczne), `analytics_events` (analityka — Art. 6(1)(f), osobny LIA)
- `settings` (konfiguracja tenanta)

### 1.3 Obowiązek Art. 28(3)(g) — zwrot lub usunięcie danych

Zgodnie z **Art. 28 ust. 3 lit. g RODO** procesor po zakończeniu świadczenia usług
— według wyboru administratora — **usuwa lub zwraca** wszelkie dane osobowe oraz usuwa
istniejące kopie.

Registro realizuje **oba** scenariusze:
- **Zwrot danych:** ✅ komenda `organizations:export-data` (sekcja 4)
- **Usunięcie / anonimizacja:** ✅ komenda `organizations:purge` (sekcja 5)

Ograniczenie: usunięcie nie obejmuje danych, których przechowywanie nakazuje **prawo Unii
lub prawo państwa członkowskiego** (Art. 28(3)(g) in fine) — tu: dokumenty księgowe/faktury
(sekcja 3). Te dane są **anonimizowane w zakresie PII osoby fizycznej**, a pozostawiane wyłącznie
w zakresie wymaganym przepisami podatkowymi.

---

## 2. Tabela retencji

Legenda działania po upływie: **USUŃ** = hard-delete; **ANONIMIZUJ** = nadpisanie PII placeholderem/NULL
z zachowaniem rekordu księgowego; **PSEUDONIMIZUJ** = zachowanie z ograniczeniem identyfikowalności.

| # | Kategoria danych | Okres retencji | Podstawa prawna | Działanie po upływie | Egzekwowane w kodzie |
|---|------------------|----------------|-----------------|----------------------|----------------------|
| 1 | **Faktury VAT** (numer, kwoty, NIP/nazwa/adres nabywcy-firmy, daty fiskalne) | 5 pełnych lat od końca roku powstania obowiązku podatkowego (konfiguracja: **6 lat** — margines na granicę roku) | **Art. 112 ustawy o VAT**; **Art. 70 §1 Ordynacji podatkowej** (przedawnienie zobowiązania) | ZACHOWAJ przez okres; po upływie USUŃ (🔴 przyszła faza) | `config/retention.php` → `legal_records_years = 6`; pola `invoice_*` zachowane w `OrganizationAnonymizationService` |
| 2 | **Dowody księgowe / płatności** (`payments`: kwoty, status, P24 session/order id, `tenant_payments`) | 5 lat (jw.) — konfiguracja 6 lat | **Art. 74 ust. 2 pkt 4 ustawy o rachunkowości** (księgi i dowody — 5 lat); Art. 112 VAT | ZACHOWAJ; `webhook_payload` (PII z bramki) ANONIMIZUJ (NULL) od razu przy purge | `anonymizePayments()` nulluje `webhook_payload`; reszta zachowana; FK `restrictOnDelete` |
| 3 | **Dane zamówień B2C** — PII osoby fizycznej poza fakturą (imię, nazwisko, e-mail, telefon, adres, PESEL, IP) | Do ustania celu / max okres roszczeń **6 lat** (B2C) | **Art. 5(1)(e) RODO** (ograniczenie przechowywania); **Art. 118 KC** (przedawnienie roszczeń ogólnych — 6 lat); **Art. 17 RODO** | ANONIMIZUJ przy purge | `anonymizeOrders()` — pola `customer_*`, `ip_address`, `rodo_accepted_ip`, `notes`; `config/retention.php` → `claims_b2c_years = 6` |
| 4 | **Dane zamówień B2B** — PII osoby kontaktowej/sygnatariusza/odbiorcy (poza danymi firmy na fakturze) | Do ustania celu / roszczenia **3 lata** (B2B) | Art. 5(1)(e) RODO; **Art. 118 KC** (roszczenia związane z działalnością gospodarczą — 3 lata) | ANONIMIZUJ przy purge (PII osób); dane firmy (NIP/REGON/KRS/nazwa) ZACHOWAJ na fakturze | `anonymizeOrders()` — `signatory_id_number`, `pickup_person_*`, `company_contact_name`; `claims_b2b_years = 3` |
| 5 | **Wizyty** (`appointments`) — PII klienta + lokalizacja usługi mobilnej + nr rejestracyjny pojazdu | Do ustania celu / roszczenia | Art. 5(1)(e), Art. 17 RODO; Art. 118 KC | ANONIMIZUJ przy purge | `anonymizeAppointments()` — `first_name/last_name/email/phone`, `location_*`, `service_location_type`, `registration_number`, `notes`, `cancellation_reason` |
| 6 | **Wypożyczenia** (`rentals`) — PII najemcy | Do ustania celu / roszczenia | jw. | ANONIMIZUJ przy purge | `anonymizeRentals()` — `first_name/last_name/email/phone`, `notes`, `cancellation_reason` |
| 7 | **PESEL** (B2C) / **nr dokumentu tożsamości** (sygnatariusz/odbiorca B2B) | Do ustania celu — dane wrażliwe/szczególnej kategorii operacyjnej, minimalizacja | Art. 5(1)(c) (minimalizacja), 5(1)(e), Art. 17 RODO | ANONIMIZUJ (NULL) przy purge | `customer_pesel`, `signatory_id_number`, `pickup_person_id_number` → NULL |
| 8 | **NIP / nazwa / adres firmy** (nabywca na fakturze) | Okres retencji faktury (6 lat) | Art. 106e ust. 1 VAT (obowiązkowe elementy faktury — nie można usunąć nabywcy); Art. 112 VAT | ZACHOWAJ (anonimizacja nabywcy niemożliwa bez naruszenia integralności faktury) | `invoice_company_name`, `invoice_nip`, `invoice_street*`, `invoice_postal_code`, `invoice_city` — **NIE** anonimizowane |
| 9 | **Dane konta tenanta** (`organizations`, `users` właściciela) | Czas trwania umowy + okres rozliczeniowy/roszczeń | Umowa (Art. 6(1)(b) RODO); Art. 118 KC | Soft-delete org + zwrot danych; USUŃ konta po purge | Soft-delete w `PurgeClosedOrganizationsCommand` (krok 3) |
| 10 | **Zgody marketingowe / RODO / regulamin** (timestampy `rodo_accepted_at`, `terms_accepted_at`, `withdrawal_exclusion_accepted_at`) | Jako dowód czynności prawnej — przez okres możliwych roszczeń | **Art. 7 ust. 1 RODO** (rozliczalność zgody); Art. 5(2) (accountability) | ZACHOWAJ timestamp (dowód); IP zapisu zgody (`rodo_accepted_ip`) ANONIMIZUJ jako PII | Timestampy zachowane; `rodo_accepted_ip` → NULL w `anonymizeOrders()` |
| 11 | **Logi aplikacyjne / audytowe** (Log::info/warning purge, eksport) | Do ustania celu bezpieczeństwa | Art. 5(1)(f) (integralność/poufność), Art. 32 RODO | 🔴 brak formalnej rotacji — DŁUG | `PurgeClosedOrganizationsCommand`, `ExportOrganizationDataCommand` piszą do logów; rotacja niezdefiniowana |
| 12 | **Analityka** (`analytics_events`) | **13 miesięcy** | Art. 6(1)(f) RODO — LIA (`analytics-gdpr-lia.md`) | USUŃ (`analytics:prune`) | `config/retention.php` → `analytics_months = 13`; przy purge org — natychmiastowy hard-delete |
| 13 | **Koszyki** (`carts`) | **7 dni** (porzucone) | Art. 5(1)(e) RODO — brak wartości po 7 dniach | USUŃ | `config/retention.php` → `carts_days = 7`; przy purge org — hard-delete |
| 14 | **Statystyki dzienne** (`statistics_daily_snapshots`) | **365 dni** | Art. 5(1)(e) — dane zagregowane, malejąca wartość | USUŃ (możliwy backfill z zamówień) | `config/retention.php` → `statistics_days = 365`; przy purge org — hard-delete |
| 15 | **Backupy** | put-beyond-use do wygaśnięcia cyklu backupu | Art. 17(1), motyw 26 RODO | 🔴 brak formalnej polityki — DŁUG (sekcja 6) | brak implementacji |
| 16 | **Pliki eksportu danych** (ZIP `exports/org-{id}/`) | **8 dni** (link podpisany ważny 7 dni + 1 dzień marginesu) | Art. 5(1)(e) RODO | USUŃ (`organizations:cleanup-exports`, codziennie 04:00) | `config/retention.php` → `export_files_days = 8`; scheduler w `routes/console.php` |

> **Uwaga interpretacyjna do okresów księgowych:** Art. 112 VAT i Art. 70 Ordynacji liczą 5 lat
> **od końca roku kalendarzowego**, w którym powstał obowiązek/upłynął termin płatności podatku.
> Efektywnie daje to do ~6 lat liczonych od daty dokumentu — dlatego `legal_records_years = 6`
> (margines na granicę roku + opóźnienie przetwarzania). Wartość jest celowo konserwatywna.

---

## 3. Anonimizacja vs przechowywanie

### 3.1 Dlaczego faktury firm zostają, a PII osób fizycznych jest anonimizowane

Serwis `OrganizationAnonymizationService` (`app/Services/Lifecycle/OrganizationAnonymizationService.php`)
rozdziela dwie kategorie pól w obrębie tego samego rekordu:

**ZACHOWANE (obowiązek księgowy):**
- numer zamówienia, statusy, kwoty (subtotal, VAT, total), kaucja, znaczniki fiskalne, daty
- dane fakturowe: `invoice_company_name`, `invoice_nip`, `invoice_street*`, `invoice_postal_code`, `invoice_city`
- dla B2B: `company_regon`, `company_krs` (występują na fakturze)
- timestampy zgód (dowód czynności prawnej)

**ANONIMIZOWANE (PII osoby fizycznej):**
- `customer_first_name`/`customer_last_name` → `"Anonimizowane"`
- `customer_email` → `anon_{id}@anonymized.local` (unikat per wiersz)
- `customer_phone`, `customer_pesel`, adres klienta, `ip_address`, `rodo_accepted_ip`, `notes` → NULL
- dla B2C (`natural_person`): dodatkowo `company_regon`/`company_krs` → NULL (JDG — REGON identyfikuje osobę)

### 3.2 Art. 106e VAT — anonimizacja nabywcy niemożliwa

**Art. 106e ust. 1 ustawy o VAT** wymienia obowiązkowe elementy faktury, w tym **imię i nazwisko
lub nazwę nabywcy oraz jego adres** (pkt 3) i **NIP nabywcy** (pkt 5). Usunięcie tych pól z faktury
naruszyłoby jej integralność jako dokumentu księgowego i kolidowałoby z obowiązkiem z Art. 112 VAT.

Dlatego **dane nabywcy-firmy na fakturze są zachowywane przez cały okres retencji** — anonimizacja
faktury VAT nie jest dopuszczalna dopóki trwa obowiązek jej przechowywania. Jest to wyjątek od prawa
do usunięcia: **Art. 17 ust. 3 lit. b RODO** (przetwarzanie niezbędne do wywiązania się z obowiązku
prawnego).

### 3.3 Pseudonimizacja vs anonimizacja (motyw 26 RODO)

- **Anonimizacja** (motyw 26 RODO): dane przestają być danymi osobowymi — nieodwracalnie nie da się
  zidentyfikować osoby. Tylko wtedy RODO przestaje obowiązywać. Nadpisanie `customer_*` placeholderem
  i NULL dąży do tego stanu.
- **Pseudonimizacja** (Art. 4 pkt 5 RODO): dane nadal są danymi osobowymi (można je powiązać z osobą
  przy użyciu dodatkowych informacji). Podlegają RODO.

> ⚠️ **Otwarta kwestia DPO:** czy obecny zabieg to faktyczna anonimizacja, czy raczej
> pseudonimizacja? Rekord zamówienia zachowuje `customer_id` (FK do `users`) oraz — dla JDG —
> `invoice_nip`/`invoice_company_name` zawierające NIP i imię/nazwisko przedsiębiorcy. Dopóki te
> powiązania istnieją, dane mogą być **re-identyfikowalne** → bliżej pseudonimizacji niż anonimizacji.
> Patrz sekcja 8 (follow-upy).

---

## 4. Prawo dostępu / przenoszenia danych (kopia danych)

### 4.1 Podstawa prawna

- **Art. 15 RODO** — prawo dostępu (kopia danych)
- **Art. 20 RODO** — prawo do przenoszenia danych (format ustrukturyzowany, powszechnie używany,
  nadający się do odczytu maszynowego)
- **Art. 28(3)(g) RODO** — zwrot danych administratorowi przy zakończeniu usługi
- **Art. 12 ust. 3 RODO** — termin realizacji: **bez zbędnej zwłoki, max 1 miesiąc** (z możliwością
  przedłużenia o 2 miesiące przy złożonych żądaniach)

### 4.2 Jak realizowane ✅ ZAIMPLEMENTOWANE

Komenda **`organizations:export-data {organization}`**
(`app/Console/Commands/ExportOrganizationDataCommand.php`) →
`OrganizationDataExportService` (`app/Services/Lifecycle/OrganizationDataExportService.php`):

1. Generuje **ZIP** zawierający per zbiór: `{dataset}.json` + `{dataset}.csv` + `manifest.json`.
   Zbiory: `orders`, `appointments`, `rentals`, `payments`, `tenant_payments`, `settings`.
2. Format spełnia Art. 20 RODO: JSON (odczyt maszynowy) + CSV (UTF-8 BOM, średniki — kompatybilny z Excel).
3. Plik zapisywany na dysku **`local`** (prywatny `storage/app/private/`) — **NIGDY** `public`
   (zawiera PII klientów).
4. Ochrona przed CSV formula injection (CWE-1236) — `sanitizeCsvValue()` prefiksuje `=+-@` apostrofem.
5. Izolacja tenanta: każde zapytanie `WHERE organization_id = ?` przez `DB::table()` (omija global scope).
6. Właściciel org otrzymuje **podpisany link** (`URL::temporarySignedRoute`) ważny **7 dni**
   (`OrganizationDataExportReadyNotification`). Pliki kasowane po 8 dniach (`organizations:cleanup-exports`).
7. `manifest.json` zawiera podstawę prawną (Art. 28(3)(g)) i notę o obowiązkach odbiorcy.

> **Uwaga:** eksport zwraca dane **administratorowi** (tenantowi). Realizacja żądań indywidualnych
> osób (Art. 15/20) wobec konkretnego klienta końcowego należy do **tenanta jako administratora** —
> Registro dostarcza dane operacyjnie, ale to tenant odpowiada za relację z podmiotem danych.

---

## 5. Procedura offboardingu i purge

### 5.1 Maszyna stanów cyklu życia

`OrganizationLifecycleState` (`app/Enums/OrganizationLifecycleState.php`):
`Active` → `Suspended` / `Closing` → `Closed` (stan terminalny).

### 5.2 Sekwencja z terminami ✅ ZAIMPLEMENTOWANE (purge PII) / 🔴 hard-delete faktur (plan)

```
[Active]
   │  przejście na Closing/Closed
   │  GUARD: OrganizationObserver::updating()
   │   → blokada gdy istnieją aktywne zobowiązania
   │     (OrganizationHasActiveObligationsException),
   │     chyba że $forceLifecycleTransition = true
   ▼
[Closing]  closing_initiated_at = now()
   │
   ▼
[Closed]   closed_at = now()
   │        purge_after = now() + retention.purge_grace_days (30 dni)
   │        ── OKNO KARENCJI 30 DNI ──
   │        (operator może przywrócić: Closing → Active czyści purge_after)
   ▼
[organizations:purge]  (scheduler: codziennie 03:00, --force)
   │   eligibility: lifecycle_state = closed AND purge_after <= now() AND deleted_at IS NULL
   │   w obrębie DB::transaction per org:
   │   1. ANONIMIZUJ PII → orders/appointments/rentals/payments
   │   2. USUŃ efemeryczne → carts, analytics_events, statistics_daily_snapshots
   │   3. SOFT-DELETE org ($org->bypassDeleteGuard = true)
   │      Rekordy księgowe (orders/payments/rentals/tenant_payments) ZOSTAJĄ (zanonimizowane).
   ▼
[Soft-deleted org + zanonimizowane rekordy księgowe]
   │
   │   🔴 PLAN (przyszła faza): po upływie legal_records_years (6 lat)
   │   hard-delete faktur — `organizations:purge-legal-records --after-years=6`
   ▼
[Pełne usunięcie]
```

### 5.3 Zabezpieczenia (defence in depth)

| Warstwa | Mechanizm | Plik |
|---------|-----------|------|
| Aplikacja (przejście stanu) | `OrganizationHasActiveObligationsException` — blokada Closing/Closed z aktywnymi zobowiązaniami | `OrganizationObserver::updating()` |
| Aplikacja (kasowanie) | `OrganizationNotClosedException` + `OrganizationHasActiveObligationsException` + `OrganizationHasLegalRecordsException` | `OrganizationObserver::deleting()` |
| Baza danych (ostateczny backstop) | FK `restrictOnDelete` na `orders`/`payments`/`tenant_payments`/`rentals` — blokuje **hard-delete** org gdy istnieją rekordy księgowe | `2026_06_30_000001_fix_lifecycle_fk_constraints.php` |

> **Ważne:** FK `RESTRICT` reaguje wyłącznie na **hard DELETE**, nie na soft-delete (UPDATE `deleted_at`).
> Dlatego purge robi soft-delete org (`bypassDeleteGuard = true`), a rekordy księgowe pozostają
> powiązane z miękko usuniętym wierszem org. Hard-delete byłby zablokowany przez FK — co jest celowe.

### 5.4 Cechy operacyjne komendy purge ✅

`PurgeClosedOrganizationsCommand` realizuje wzorzec destrukcyjnych komend (`console-commands.md`):
`--dry-run`, `--force`, audit log (`Log::info`/`Log::warning`/`Log::error`), `DB::transaction` per org
z `catch \Throwable` (jedna porażka nie blokuje całej kohorty), idempotentna anonimizacja.

---

## 6. Backupy

### 6.1 Stanowisko prawne

**Art. 17 ust. 1 RODO** (prawo do usunięcia) w połączeniu z **motywem 26** wymaga, by usunięcie
obejmowało także kopie zapasowe. Standard branżowy (ICO, EROD): jeśli natychmiastowe usunięcie
z backupu jest technicznie niewykonalne, dane należy **"put beyond use"** — odizolować, zabezpieczyć
przed dalszym przetwarzaniem i usunąć przy najbliższym cyklu nadpisania backupu, z udokumentowaną
polityką retencji kopii.

### 6.2 Stan implementacji 🔴 DŁUG

**Brak formalnej, udokumentowanej polityki backupów oraz procedury propagacji anonimizacji/usunięcia
do kopii zapasowych.** Obecnie purge działa na żywej bazie; backupy (jeśli istnieją na poziomie infra)
nie są objęte procesem put-beyond-use.

**Do decyzji DPO / DevOps:**
- Zdefiniować cykl rotacji backupów (np. 30 dni) i udokumentować jako część okresu retencji.
- Procedura: po anonimizacji w bazie żywej, dane w backupach wygasają wraz z rotacją — udokumentować
  że backupy nie są przywracane selektywnie do celów innych niż disaster recovery.
- Zapisać stanowisko "put-beyond-use" w niniejszej polityce po wdrożeniu.

---

## 7. Dokumenty wymagane (checklist)

### 7.1 Umowa powierzenia przetwarzania (DPA) — Art. 28 ust. 3 RODO 🔴 DŁUG

Umowa Registro (procesor) ↔ tenant (administrator). **Musi zawierać** (Art. 28 ust. 3):

- [ ] Przedmiot, czas, charakter i cel przetwarzania
- [ ] Rodzaj danych osobowych i kategorie osób, których dane dotyczą
- [ ] Obowiązki i prawa administratora
- [ ] (a) przetwarzanie wyłącznie na udokumentowane polecenie administratora
- [ ] (b) zobowiązanie osób upoważnionych do zachowania poufności
- [ ] (c) środki bezpieczeństwa wymagane Art. 32 (szyfrowanie, izolacja tenanta, kontrola dostępu)
- [ ] (d) warunki korzystania z podprocesorów (P24, dostawca hostingu/poczty) + lista
- [ ] (e) pomoc administratorowi w realizacji żądań osób (Art. 15–22)
- [ ] (f) pomoc w spełnieniu obowiązków z Art. 32–36 (bezpieczeństwo, zgłaszanie naruszeń, DPIA)
- [ ] (g) **usunięcie lub zwrot danych po zakończeniu usługi** → ✅ pokryte technicznie (sekcje 4–5)
- [ ] (h) udostępnianie informacji do wykazania zgodności + audyty
- [ ] Lista podprocesorów: Przelewy24 (płatności), dostawca poczty (notyfikacje), hosting

### 7.2 Rejestr czynności przetwarzania (RCPD) — Art. 30 RODO 🔴 DŁUG

Registro jako procesor prowadzi **rejestr kategorii czynności** (Art. 30 ust. 2). **Musi zawierać:**

- [ ] Nazwa i dane kontaktowe procesora (Registro) oraz każdego administratora (tenanta), na rzecz
      którego działa, + ew. IOD
- [ ] Kategorie przetwarzań dokonywanych w imieniu administratorów (rezerwacje, wypożyczenia,
      zamówienia, płatności, analityka)
- [ ] Ew. transfery do państw trzecich (jeśli hosting/podprocesor poza EOG) + zabezpieczenia
- [ ] Ogólny opis technicznych i organizacyjnych środków bezpieczeństwa (Art. 32):
      izolacja per-tenant (`organization_id`), szyfrowanie, kontrola dostępu, audit log,
      pseudonimizacja session ID (analityka)

### 7.3 Pozostałe

- [ ] 🟡 LIA dla analityki — ✅ istnieje (`app/docs/legal/analytics-gdpr-lia.md`)
- [ ] 🔴 LIA dla cart-recovery e-mail (gdy aktywowany `carts.customer_email`) — zob. LIA, open item
- [ ] 🔴 Wzór klauzuli informacyjnej dla tenantów (do polityki prywatności tenanta)

---

## 8. Follow-upy / kwestie do przeglądu DPO

| # | Kwestia | Opis | Status |
|---|---------|------|--------|
| 1 | **JDG — NIP/nazwa firmy = osoba fizyczna** | Dla jednoosobowej działalności gospodarczej `invoice_nip` i `invoice_company_name` zawierają NIP i imię/nazwisko przedsiębiorcy = dane osobowe. Są zachowywane pod Art. 112 VAT. DPO musi potwierdzić, czy po upływie okresu retencji ich zachowanie jest proporcjonalne, czy wystarczy pseudonimizacja. `FIXME(DPO)` w `anonymizeOrders()`. | 🔴 do decyzji |
| 2 | **`order_status_history.properties`** | Tabela `order_status_history` (`2026_03_26_000006`) ma kolumnę JSON `properties`, która może zawierać PII (snapshot zmian). **Nie jest** obecnie anonimizowana ani usuwana przy purge. | 🔴 dług — dodać do purge/anonimizacji |
| 3 | **`customer_id` FK — re-identyfikacja** | Zamówienia po anonimizacji zachowują `customer_id` (FK do `users`). Dopóki rekord `users` istnieje, zamówienie jest re-identyfikowalne → to **pseudonimizacja, nie anonimizacja**. Rozważyć NULL-owanie `customer_id` lub usuwanie/anonimizację konta `users` przy purge. | 🔴 do decyzji |
| 4 | **Hard-delete rekordów księgowych po retencji** | Po upływie `legal_records_years` (6 lat) faktury powinny zostać trwale usunięte. Brak implementacji — komentarz `FUTURE` w `PurgeClosedOrganizationsCommand`. Wymaga komendy `organizations:purge-legal-records --after-years=6` + zdjęcia/obejścia FK `RESTRICT`. | 🔴 plan |
| 5 | **Formalna polityka backupów (put-beyond-use)** | Sekcja 6 — brak udokumentowanej rotacji backupów i propagacji usunięcia. | 🔴 dług |
| 6 | **Rotacja logów aplikacyjnych** | Logi purge/eksportu (mogą zawierać org_id, e-mail właściciela) bez zdefiniowanej rotacji/retencji. | 🔴 dług |
| 7 | **DPA + RCPD** | Dokumenty formalne (sekcja 7) — brak. Krytyczne dla zgodności Art. 28/30 niezależnie od technicznej implementacji. | 🔴 dług |
| 8 | **Konsekwencja okresów roszczeń vs retencja** | `claims_b2c_years = 6` / `claims_b2b_years = 3` są zdefiniowane w configu, ale purge wyzwala anonimizację po `purge_grace_days` (30 dni) od zamknięcia org — **nie** po okresie roszczeń per zamówienie. DPO/architekt: potwierdzić, czy anonimizacja PII przy offboardingu org przed upływem okresu roszczeń jest akceptowalna (org zamykana = brak dalszej relacji), czy okresy `claims_*` mają być egzekwowane per rekord. | 🟡 do potwierdzenia |

---

## Podsumowanie zgodności technicznej

**Zaimplementowane i zweryfikowane (✅):**
- Rozdział ról procesor/administrator z technicznym wsparciem obu ścieżek Art. 28(3)(g)
- Anonimizacja PII z zachowaniem danych księgowych (`OrganizationAnonymizationService`) — z poprawnym
  rozróżnieniem B2C/B2B i wyjątkiem Art. 106e VAT dla nabywcy-firmy
- Eksport danych (zwrot administratorowi) w formacie JSON+CSV, prywatny dysk, podpisany link 7 dni
- Procedura offboardingu: karencja 30 dni → purge (anonimizacja + usunięcie efemerycznych + soft-delete)
- Trójwarstwowy backstop kasowania (observer + 3 wyjątki + FK RESTRICT)
- Skonfigurowane okresy retencji z podstawą prawną (`config/retention.php`), wraz z auto-czyszczeniem
  eksportów, koszyków, analityki, statystyk

**Luki compliance do decyzji DPO (🔴):**
1. Brak **DPA** (Art. 28) i **RCPD** (Art. 30) — dokumenty formalne, krytyczne.
2. **Hard-delete faktur po 6 latach** niezaimplementowany (tylko soft-delete org + anonimizacja).
3. **`order_status_history.properties`** — JSON z możliwym PII pomijany przy purge.
4. **`customer_id` FK** pozostaje po anonimizacji → ryzyko re-identyfikacji (pseudonimizacja vs anonimizacja).
5. **JDG** — retencja `invoice_nip`/`invoice_company_name` (dane osoby fizycznej) po okresie retencji.
6. **Backupy** — brak formalnej polityki put-beyond-use.
7. **Rotacja logów** aplikacyjnych z PII niezdefiniowana.
8. **Okresy roszczeń** (`claims_b2c/b2b`) zdefiniowane, ale nie egzekwowane per rekord — anonimizacja
   wyzwalana zamknięciem org, nie upływem okresu roszczeń.
