# Lifecycle & Retro-Audit — Security Decisions Register

> **Sesja 2026-06-29 / 2026-06-30** — trwały rejestr decyzji i napraw bezpieczeństwa z dwóch równoległych nurtów pracy:
> 1. **Retro-audyt** ~19 PR scalonych bez review od `v0.10.1` → fix-PR **#84 / #85 / #86**.
> 2. **Tenant lifecycle** (Fazy 5.0–5.3b) — co bramka code-review + security-audit wyłapała PRZED mergem → PR **#83 / #87 / #88 / #89**.
>
> Cel: żeby decyzje "CO wyłapaliśmy i JAK naprawiliśmy" nie zginęły w opisach PR.
> Każdy wpis zweryfikowany w kodzie / git logu (nie z pamięci). Format: **Tytuł | Severity | OWASP/RODO | Gdzie | Ryzyko | Jak naprawiono | PR**.

---

## Tabela podsumowująca

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| RA-1 | Cross-tenant PII leak — OrderResource customer Select | 🔴 Critical | ✅ fixed (#85) |
| RA-2 | Stored XSS — `javascript:` URL w analytics href | 🔴 Critical | ✅ fixed (#86) |
| RA-3 | Mass-assignment — Organization/TenantPayment billing `$fillable` | 🟠 High | ✅ fixed (#85) |
| RA-4 | Martwy server-side funnel — `VALID_EVENTS` dropuje eventy | 🟡 Medium | ✅ fixed (#86) |
| RA-5 | Dual queue worker — jobs niewidoczne w Horizonie | 🟡 Medium | ✅ fixed (#84) |
| RA-6 | Order bez audit trail / mutowalne pola finansowe | 🟠 High | ✅ fixed (#85) |
| RA-7 | Brak walidacji PESEL/NIP/REGON w admin edit Order | 🟡 Medium | ✅ fixed (#85) |
| RA-8 | Analytics GDPR — query-string z tokenami/emailami w storze | 🟠 High | ✅ fixed (#86) |
| RA-9 | Horizon dashboard otwarty dla każdego zalogowanego | 🟡 Medium | ✅ fixed (#84) |
| LC-1 | `is_active` ⇄ `lifecycle_state` desync + mass-assignment (F003/F004) | 🟠 High | ✅ fixed (#83) |
| LC-2 | Niekompletna anonimizacja PII (GPS, plate, notes, deposit_notes) | 🔴 Critical | ✅ fixed (#88) |
| LC-3 | CSV formula-injection w eksporcie danych tenanta | 🟠 High | ✅ fixed (#89) |
| LC-4 | Tenant data export — access control (signed URL + super-admin + traversal) | 🟠 High | ✅ fixed (#89) |
| LC-5 | Export ZIP-y zalegają na dysku (RODO art. 5(1)(e)) | 🟡 Medium | ✅ fixed (#89) |
| LC-6 | Per-tenant rate-limit martwy (middleware order) | 🟡 Medium | ✅ fixed (#86) |
| PR-1 | Bramka review obowiązkowa przed mergem | proces | ✅ enforced |
| PR-2 | Worktree-sync zawodny + `composer dump-autoload` psuje shared autoload | proces | ⚠️ mitigated |
| DPO-1 | JDG `invoice_company_name`/`invoice_nip` retention po Art.112 | follow-up | 🟡 DPO review |
| DPO-2 | `order_status_history.properties` — potencjalne PII | follow-up | 🟡 audit |
| DPO-3 | `customer_id` FK = pseudonimizacja, nie anonimizacja | follow-up | 🟡 DPO review |
| DPO-4 | Backup policy — anonimizacja nie sięga backupów | follow-up | 🟡 open |
| DPO-5 | Hard-delete legal records po 6 latach (Faza 5.4) | follow-up | 🟡 open |
| DPO-6 | Przelewy24 webhook — org_id pochodzi z order (pre-existing) | follow-up | 🟡 open |
| LC-7 | `forceLifecycleTransition` + masowe anulowanie — super-admin only, brak automatycznego zwrotu | 🟠 High | ✅ documented (5.4a) |

---

## 1. Retro-audyt findings (PR #84–#86)

Kontekst: ~19 PR scalono do `develop` bez bramki review od `v0.10.1`. Retro-audyt znalazł 3 realne 🔴/🟠 oraz kilka 🟡. Naprawy rozbite na 3 fix-PR wg domeny (orders / analytics / infra).

### RA-1 — Cross-tenant PII leak w OrderResource customer Select
- **Severity:** 🔴 Critical
- **OWASP/RODO:** A01 Broken Access Control (IDOR / cross-tenant) · RODO art. 5(1)(f) integralność i poufność
- **Gdzie:** `app/Filament/Resources/OrderResource.php` (`Select::make('user_id')`)
- **Ryzyko:** Select klienta w panelu admina (`/admin`) odpytywał **wszystkich userów w systemie** — bez scope per-tenant. Wybór usera z innej organizacji autofillował jego dane → wyciek **PESEL / NIP / REGON / adres** klienta innego tenanta do operatora.
- **Jak naprawiono:** Scope query po `TenantFeature::currentTenant()?->id`. Kluczowe: **fail-SAFE, nie fail-open** — gdy `currentTenant` jest `null`, query dostaje `whereNull('id')` (zero wyników) i `$set('user_id', null)`, zamiast pokazać wszystkich. `visible()` closures używają `Get` (live form state), nie nieaktualnego `$record`. Test: `tests/Feature/Orders/OrderSecurityTest.php` (16 asercji).
- **PR:** #85

### RA-2 — Stored XSS: `javascript:` URL w analytics href
- **Severity:** 🔴 Critical
- **OWASP/RODO:** A03 Injection (Stored XSS) · A07
- **Gdzie:** `app/Http/Controllers/Api/EventTrackingController.php`, `app/Jobs/IngestAnalyticsEventsJob.php`, `resources/views/.../analytics-overview.blade.php`
- **Ryzyko:** Event tracker przyjmował dowolny `url`/`referrer`. Wartość `javascript:...` lądowała w bazie i była renderowana jako `<a href="...">` w panelu analityki admina → wykonanie skryptu w kontekście admina (stored XSS).
- **Jak naprawiono:** Walidacja schematu URL na ingestion (`starts_with http`) — nie-http odrzucane `422`. Blade renderuje `<a>` tylko dla URL-i http (defense-in-depth na warstwie widoku). Test potwierdza odrzucenie javascript-URL (422) + `IngestAnalyticsEventsJobTest` (8).
- **PR:** #86

### RA-3 — Mass-assignment: billing fields w `$fillable`
- **Severity:** 🟠 High
- **OWASP/RODO:** A08 Software and Data Integrity Failures · A04 Insecure Design
- **Gdzie:** `app/Models/Organization.php`, `app/Models/TenantPayment.php`
- **Ryzyko:** Pola billingowe (`subscription_status`, `monthly_fee`, `subscribed_at`, `subscription_expires_at`) były mass-assignable na `Organization`; `organization_id` / `recorded_by` na `TenantPayment`. Otwierało eskalację stanu subskrypcji / podmianę właściciela płatności przez `fill()`.
- **Jak naprawiono:** Usunięte z `$fillable`. Pola subskrypcji ustawiane wyłącznie jawnym property-set + `save()` (komentarz w `Organization.php:81-83` dokumentuje wymóg). Reguła w `.claude/rules/models.md`.
- **PR:** #85

### RA-4 — Martwy server-side funnel (VALID_EVENTS)
- **Severity:** 🟡 Medium
- **OWASP/RODO:** A04 Insecure Design (cicha utrata danych)
- **Gdzie:** `app/Jobs/IngestAnalyticsEventsJob.php` (`VALID_EVENTS`)
- **Ryzyko:** Od PR #73 allowlist `VALID_EVENTS` nie zawierał eventów server-side (`cart.abandoned`, `checkout.started`, `checkout.submitted`, `order.completed`) — były **cicho dropowane**. Cały server-side funnel był ciemny (analytics blind), bez błędu.
- **Jak naprawiono:** Dodano brakujące eventy do `VALID_EVENTS`.
- **PR:** #86

### RA-5 — Dual queue worker: jobs niewidoczne w Horizonie
- **Severity:** 🟡 Medium (operacyjne — utrata widoczności)
- **OWASP/RODO:** A09 Security Logging and Monitoring Failures
- **Gdzie:** `docker-compose.yml`, `docker-compose.dev.yml`, `config/horizon.php`, `routes/console.php`, `HorizonServiceProvider`
- **Ryzyko:** Surowy service `queue` (`queue:work`) działał równolegle z Horizonem i przechwytywał te same kolejki. Jobs złapane przez raw workera były niewidoczne w dashboardzie Horizona (failed list / autoscaling / metryki martwe). 3-tygodniowy backlog analytics przeszedł niezauważony.
- **Jak naprawiono:** Usunięto raw service `queue` z obu plików compose — Horizon jedynym konsumentem. Dodano `horizon:snapshot` co 5 min (były puste metryki). `config/horizon.php waits` rozszerzone na analytics/emails/reminders; master `memory_limit` 64→128 MB. (Szczegóły także w `ci-cd-troubleshooting.md`, incydent 2026-06-29.)
- **PR:** #84

### RA-6 — Order: brak audit trail + mutowalne pola finansowe
- **Severity:** 🟠 High
- **OWASP/RODO:** A09 Logging Failures · A08 Integrity · RODO art. 5(2) rozliczalność
- **Gdzie:** `app/Models/Order.php`
- **Ryzyko:** Brak ścieżki audytu zmian PII/finansowych na zamówieniach; pola krytyczne (`organization_id`, kwoty, znaczniki zgód RODO/regulamin) można było nadpisać po utworzeniu.
- **Jak naprawiono:** Trait `Auditable` (allowlist `$auditInclude`, m.in. `paid_at`/`cancelled_at`/`completed_at` — audyt finansowy) + immutable-field guard w `saving()` (`organization_id`, kwoty, `rodo_accepted_at`, `terms_accepted_at` — rzuca wyjątek przy próbie zmiany). Znane ograniczenie udokumentowane: `saveQuietly()` omija guard (caveat w `models.md`); PESEL w `audit_logs` podlega retencji.
- **PR:** #85

### RA-7 — Brak walidacji polskich ID w admin edit Order
- **Severity:** 🟡 Medium
- **OWASP/RODO:** A04 Insecure Design (boundary validation)
- **Gdzie:** `app/Filament/Resources/OrderResource.php`
- **Ryzyko:** Formularz edycji zamówienia w panelu przyjmował dowolny PESEL/NIP/REGON bez kontroli sumy kontrolnej → śmieciowe/niepoprawne dane na fakturze.
- **Jak naprawiono:** Dodano reguły `ValidPolishPESEL` / `ValidPolishNIP` / `ValidPolishREGON` do formularza edycji.
- **PR:** #85

### RA-8 — Analytics GDPR: query-string z PII w storze
- **Severity:** 🟠 High
- **OWASP/RODO:** RODO art. 5(1)(c) minimalizacja · art. 25 privacy by design
- **Gdzie:** `app/Jobs/IngestAnalyticsEventsJob.php`, JS tracker
- **Ryzyko:** Pełne URL-e (z query-stringami zawierającymi tokeny/emaile), nieograniczona długość property, `anonymous_id` i `captureUtm` ignorujące DNT.
- **Jak naprawiono:** Strip query-string z `url`/`referrer` (zachowując port — fix dla dev `:8444`); cap długości property string; `anonymous_id` leniwy za DNT; `captureUtm` bramkowane DNT; walidator property odrzuca wartości nie-skalarne (nested-array bypass).
- **PR:** #86

### RA-9 — Horizon dashboard otwarty dla każdego zalogowanego
- **Severity:** 🟡 Medium
- **OWASP/RODO:** A01 Broken Access Control
- **Gdzie:** `app/Providers/HorizonServiceProvider.php`
- **Ryzyko:** Gate zwracał `true` dla dowolnego zalogowanego usera w non-prod → dostęp do kolejek/payloadów jobów (mogących zawierać PII).
- **Jak naprawiono:** Gate ograniczony do `hasRole('super-admin')` we wszystkich środowiskach.
- **PR:** #84

---

## 2. Lifecycle gate findings (PR #83, #87–#89)

Kontekst: każda Faza lifecycle przechodziła `code-reviewer` + `agent-security-audit-specialist` PRZED mergem. Poniżej to, co bramka **zatrzymała jako blocker** i jak zamknięto.

### LC-1 — `is_active` ⇄ `lifecycle_state` desync + mass-assignment (F003/F004)
- **Severity:** 🟠 High
- **OWASP/RODO:** A04 Insecure Design · A08 Integrity
- **Gdzie:** `app/Models/Organization.php`, `app/Observers/OrganizationObserver.php`, `app/Filament/.../Platform/OrganizationResource.php`, state machine
- **Ryzyko:** Dwa źródła prawdy o aktywności tenanta. Surowy toggle `is_active` w panelu platform (F004) mógł rozjechać się ze `lifecycle_state`; `is_active` było mass-assignable (F003) → tenant "aktywny" mimo stanu Closed (omijając guardy obligacji).
- **Jak naprawiono:** `is_active` usunięte z `$fillable` (teraz **derived** z `lifecycle_state` w `creating()`). `OrganizationObserver::updating()` waliduje przejścia przez state machine, blokuje Closing/Closed gdy są aktywne obligacje (flaga `force` jako bypass). Surowy toggle zastąpiony akcjami Suspend/Reactivate/InitiateClosing. State machine hardening: `assertTransitionAllowed()` (W4), `transitions()` private (W6), `canTransition()` waliduje string input (W7).
- **PR:** #83 (Faza 5.1); FK backstop + lifecycle resolution dokończone w #87 (5.2)

### LC-2 — Niekompletna anonimizacja PII (re-identyfikacja po purge)
- **Severity:** 🔴 Critical
- **OWASP/RODO:** RODO art. 17 prawo do usunięcia · art. 5(1)(c) minimalizacja
- **Gdzie:** `app/Services/Lifecycle/OrganizationAnonymizationService.php`, `app/Console/Commands/PurgeClosedOrganizationsCommand.php`
- **Ryzyko:** Pierwsza wersja anonimizacji pomijała pola, które same w sobie re-identyfikują osobę po purge:
  - **appointments:** `location_address` / `lat` / `lng` / `components` / `place_id` / `service_location_type` (adres + GPS klienta mobilnego = primary PII), `registration_number` (tablica rejestracyjna), `notes`, `cancellation_reason`
  - **rentals:** `notes`, `cancellation_reason`
  - **orders:** `deposit_notes`; dla `customer_type=natural_person` (JDG) — `company_regon` / `company_krs` (REGON JDG identyfikuje osobę)
- **Jak naprawiono:** Rozszerzono anonimizację o wszystkie powyższe pola. Zachowano dane księgowe (Art. 112 VAT: `invoice_*` NIP/firma, kwoty, `order_number`, daty). Serwis używa `DB::table()` (omija immutable guard Order — nadpisuje wyłącznie PII). Per-org resilience: akumulacja błędów, kontynuacja kohorty zamiast fail-fast. Testy: wszystkie nowe pola wyczyszczone + business zachowuje REGON + cross-org isolation (`OrganizationPurgeTest`, 14+ asercji).
- **PR:** #88 (Faza 5.3a; blocker złapany przez review, fix-commit `00fe083`)

### LC-3 — CSV formula-injection w eksporcie danych tenanta
- **Severity:** 🟠 High
- **OWASP/RODO:** A03 Injection (CSV/Formula Injection)
- **Gdzie:** `app/Services/Lifecycle/OrganizationDataExportService.php` (`sanitizeCsvValue`)
- **Ryzyko:** Eksport danych tenanta do CSV. Wartość zaczynająca się od `=` `+` `-` `@` (np. wpisana przez klienta w `notes`) wykonałaby się jako formuła po otwarciu w Excel/LibreOffice u właściciela.
- **Jak naprawiono:** `sanitizeCsvValue()` prefiksuje wartości zaczynające się od `= + - @` (neutralizacja formuły), zaaplikowane przez `array_map` na każdym wierszu CSV (`fputcsv` z separatorem `;`, UTF-8 BOM). Test CSV-escape.
- **PR:** #89 (Faza 5.3b; blocker review)

### LC-4 — Tenant data export: access control
- **Severity:** 🟠 High
- **OWASP/RODO:** A01 Broken Access Control · A04 Insecure Design · RODO art. 28(3)(g) / art. 12(3)
- **Gdzie:** `app/Services/Lifecycle/OrganizationDataExportService.php`, route `platform.organization.data-export` + cienki controller
- **Ryzyko:** Eksport ZIP z pełnym kompletem danych tenanta (orders+invoices, appointments, rentals, payments, settings) to skoncentrowane PII. Bez twardej kontroli dostępu = IDOR / wyciek między tenantami.
- **Jak naprawiono:** ZIP zapisywany na **prywatnym** dysku `local` (`storage/app/private`) — nigdy public. Download tylko przez **signed URL** (`temporarySignedRoute`) **albo** rola super-admin; guard path-traversal; `StreamedResponse`. TTL signed URL skrócony 30→**7 dni** (minimalizacja PII). `throttle:10,1440` na route. Notyfikacja `ShouldBeUnique` (per-org, 1h). Testy: signature valid/expired/tampered/missing, super-admin, 404, traversal, CSV-escape, null-owner, cleanup (`OrganizationDataExportTest`, 20).
- **PR:** #89

### LC-5 — Export ZIP-y zalegają na dysku
- **Severity:** 🟡 Medium
- **OWASP/RODO:** RODO art. 5(1)(e) ograniczenie przechowywania
- **Gdzie:** `app/Console/Commands/CleanupOrganizationExportsCommand.php`, `config/retention.php`
- **Ryzyko:** Wygenerowane ZIP-y z PII pozostawały na dysku bezterminowo po pobraniu.
- **Jak naprawiono:** `organizations:cleanup-exports` + `config retention.export_files_days=8` + dzienny schedule. Unlink temp-file na wyjątku w pętli chunków; null-check `fopen()`.
- **PR:** #89

### LC-6 — Per-tenant rate-limit martwy (middleware order)
- **Severity:** 🟡 Medium
- **OWASP/RODO:** A04 Insecure Design · A09
- **Gdzie:** `routes/api.php`, `app/Providers/AppServiceProvider.php`
- **Ryzyko:** Dodano per-tenant bucket (600/min) na analytics, ale `throttle:analytics` był w łańcuchu **przed** `ResolveTenant` → bucket nie miał tenanta i nie ewaluował (martwy limit).
- **Jak naprawiono:** Zamieniono kolejność na `[ResolveTenant, throttle:analytics]` — bucket per-tenant faktycznie się liczy. Per-IP (120/min) zachowany jako warstwa bazowa.
- **PR:** #86 (review fold-in)

---

## 3. Zasady procesowe (review gate, worktree, autoload)

### PR-1 — Bramka code-review OBOWIĄZKOWA przed mergem
- Testy zielone + Pint ≠ review. `code-reviewer` (+ `agent-security-audit-specialist` dla zmian destrukcyjnych / multi-tenant) jest **wymagany przed commit/merge**.
- Właściciel zganił **2×** za pominięcie bramki — to ona złapała LC-1 (F003/F004), LC-2 (niekompletną anonimizację) i LC-3 (CSV injection) jako blockery, zanim trafiły do `develop`.
- Cały ten retro-audyt (sekcja 1) istnieje, bo ~19 PR scalono bez tej bramki od `v0.10.1`. Lekcja: brak review = dług bezpieczeństwa odkrywany retrospektywnie.
- Reguła utrwalona: `.claude/agent-memory/.../feedback_code_review_gate.md`.

### PR-2 — Worktree-sync zawodny + `composer dump-autoload` psuje shared autoload
- Agenci `isolation: worktree` **nie synchronizują niezawodnie** do głównego drzewa — po pracy agenta zawsze **weryfikuj obecność plików** w main i w razie potrzeby ręcznie zaaplikuj patch z worktree.
- `composer dump-autoload` uruchomiony **wewnątrz worktree** psuje współdzielony autoload (worktree współdzieli `vendor/` przez ścieżki) → klasy nie ładują się w main. Nie regeneruj autoload z worktree.
- Reguła utrwalona: `.claude/agent-memory/.../feedback_isolated_worktree_sync.md`.

---

## Otwarty dług bezpieczeństwa / DPO review

Pozycje zidentyfikowane, ale świadomie odroczone — każda wymaga albo opinii DPO, albo decyzji architektonicznej zależnej od skali. Pełny kontekst: `app/docs/features/tenant-lifecycle.md` (sekcja "Faza 5.3a Follow-ups / DPO Review").

| ID | Pozycja | Opis | Akcja |
|----|---------|------|-------|
| **DPO-1** | JDG na fakturach | Dla `customer_type=natural_person` z `invoice_company_name`/`invoice_nip` (JDG "Jan Kowalski") — dane zachowane z Art. 112 VAT. Po upływie okresu retencji: czy dalsze przechowywanie jest proporcjonalne (art. 5(1)(c))? Obecnie safe-default = zachowuje zawsze. | DPO → ew. update `PRESERVED` w `OrganizationAnonymizationService` |
| **DPO-2** | `order_status_history.properties` | Kolumna JSON może zawierać PII (imiona/adresy logowane przy przejściach statusu). Anonimizacja jej nie dotyka. | Audyt callerów piszących `properties`; jeśli PII → `anonymizeOrderStatusHistory()` |
| **DPO-3** | `customer_id` FK | Orders/appointments/rentals zachowują `customer_id` (FK→users) = **pseudonimizacja**, nie pełna anonimizacja. True-anon wymaga `customer_id = null` (kolumna musi stać się nullable). | DPO → czy pseudonimizacja wystarcza przy podstawie Art. 112 |
| **DPO-4** | Backup policy | Anonimizacja/purge dotyka tylko live DB — **backupy** nadal zawierają PII do czasu rotacji. Brak udokumentowanej polityki retencji/rotacji backupów względem Art. 17. | Zdefiniować backup retention + procedurę usunięcia z backupów |
| **DPO-5** | Hard-delete legal po 6 latach | Faza 5.4 (nie zaimplementowana): hard-delete orders/payments po `closed_at + legal_records_years (6)`. Wymaga check `closed_at + 6yr <= now()` i zdjęcia FK `RESTRICT` przed delete. Obecnie `forceDelete()` zarezerwowany. | Implementacja Faza 5.4 |
| **DPO-6** | Przelewy24 webhook org_id (pre-existing) | `Przelewy24Service::handleWebhook()` wyprowadza `organization_id` z dopasowanego `order` (`Przelewy24Service.php:106,122`), nie z samego payloadu webhooka. Sygnatura webhooka jest weryfikowana (`:76`), ale przypisanie tenanta zależy od poprawności lookupu order. Pre-existing — poza zakresem tej sesji. | Audyt: potwierdzić że order-lookup jest jednoznaczny per-tenant i odporny na collision `p24_session_id` |

---

## LC-7 — `forceLifecycleTransition` + masowe anulowanie (Faza 5.4a)

- **Severity:** 🟠 High
- **OWASP/RODO:** A01 Broken Access Control · RODO art. 5(1)(a) (purpose limitation) · art. 6 (lawfulness)
- **Gdzie:** `app/Actions/Offboarding/StartOrganizationOffboarding.php`, `app/Jobs/CancelInFlightObligationsJob.php`, `app/Filament/Platform/Resources/OrganizationResource.php`

### Ryzyko

1. **`forceLifecycleTransition = true` bypasses obligation guard.** Bez tego mechanizmu operator nie mógłby zainicjować zamknięcia tenanta z aktywnymi zobowiązaniami. Mechanizm jest celowy, ale musi być chroniony przed nadużyciem.

2. **Masowe anulowanie zobowiązań klientów.** `CancelInFlightObligationsJob` anuluje **wszystkie** aktywne zamówienia, wizyty i wypożyczenia danego tenanta — w tym opłacone (`paid`) i `in_progress`. Błędne wywołanie (np. przez bug w autoryzacji) skutkuje masową anulacją i emailami do klientów.

3. **Brak automatycznych zwrotów.** Paid orders i rental deposits są tylko flagowane w `Log::info`. Zwrot MUSI być wykonany manualnie przez operatora przez panel Przelewy24.

4. **`in_progress → cancelled` w state machine.** Rozszerzenie `OrderStatusStateMachine` i `OrderService::cancel()` o exceptional path. Loguje `Log::warning` przy każdym takim anulowaniu.

### Zabezpieczenia

| Warstwa | Mechanizm |
|---------|-----------|
| Autoryzacja akcji Filament | `->authorize(fn ($record) => auth()->user()->can('force-lifecycle', $record))` — wymaga polisy `super-admin` |
| Potwierdzenie | `->requiresConfirmation()` z modalem pokazującym liczby zobowiązań i ostrzeżenie o refundach |
| Audit log | `Log::info('StartOrganizationOffboarding: offboarding initiated', [...])` z org_id, org_name, timestamp |
| Idempotentność | `CancelInFlightObligationsJob` używa `whereIn` (terminal statuses wykluczone) — wielokrotne uruchomienie nie zmienia wyniku |
| Flagowanie refundów | Paid orders i deposits → `Log::info` z order_id / rental_id / kwotami — NIE znikają cicho |
| `in_progress` warning | `Log::warning` przy każdym wyjątkowym anulowaniu in_progress order |

### Decyzja projektowa — brak automatycznych zwrotów

Przelewy24 nie ma publicznie dostępnego API do automatycznych zwrotów (wymagałoby integracji z panelem merchantów lub osobnym endpointem refund który nie jest obecnie skonfigurowany). Decyzja świadoma: **flaga + log** zamiast brak informacji. Operator ma `Log::info` z pełnym kontekstem i wykonuje zwrot manualnie.

---

## LC-8 — Closure request flow & audit log (Faza 5.5 + 5.6)

- **Severity wejściowe:** 🔴 1 blocker + 🟠 1 + 🟡 kilka (wszystkie naprawione przed mergem)
- **OWASP/RODO:** A01 Broken Access Control (IDOR) · A04 Insecure Design (TOCTOU) · A08 Data Integrity (audit log) · RODO art. 5(1)(c) minimalizacja
- **Gdzie:** `app/Filament/Pages/SystemSettings.php` (`requestClosure()`), `app/Filament/Platform/Resources/OrganizationResource.php` (`clearClosureRequest`), `app/Models/OrganizationLifecycleLog.php`, `app/Notifications/OrganizationClosureRequestedNotification.php`

### Znalezione i naprawione (code-review + security audit gate)

| # | Severity | Problem | Naprawa |
|---|----------|---------|---------|
| 1 | ⚪ | ~~**Notification fan-out** — `ShouldBeUnique` na notyfikacji wielo-odbiorczej → tylko **1 z N** super-adminów dostaje mail.~~ **WYCOFANE 2026-08-12: to nigdy nie miało miejsca.** Objawu nikt nie zaobserwował, a mechanizm jest niemożliwy — `ShouldBeUnique` na notyfikacji jest w Laravelu 12.60.2 martwe (lock zakłada tylko `PendingDispatch`, notyfikacje idą przez `Bus::dispatch` na `SendQueuedNotifications`, które tego interfejsu nie implementuje). Dowód empiryczny: 5 odbiorców → 5 dostarczeń. `ShouldBeUnique` nigdy nie zostało z niczego usunięte (`git log -S` po całej historii `app/Notifications/`). | Atomowy guard `closure_requested_at` zostaje — to właściwe miejsce na dedup, niezależnie od błędnego uzasadnienia. → `.claude/rules/notifications.md` |
| 2 | 🟠 | **Mass-assignment** — `$guarded = []` na audit logu (łamie `models.md`). | Explicit `$fillable` (whitelist 7 kolumn). |
| 3 | 🟠 | **TOCTOU race** — dwa równoległe requesty przechodzą null-check i podwójnie logują/notyfikują. | Atomowy `whereNull('closure_requested_at')->update(...)`; tylko zwycięski UPDATE kontynuuje. Test `test_request_closure_is_atomic_on_double_call`. |
| 4 | 🟡 | `clearClosureRequest` na org już w `Closing` zostawia mylący stan (kolumna `—` mimo trwającego zamykania). | Dynamiczny `modalDescription` ostrzega operatora; czyszczenie flagi NIE cofa procesu (→ Reaktywuj). |
| 5 | 🟡 | Brak `->authorize()` na akcji tenanta (tylko page `canAccess()`). | Dodano `->authorize(hasAnyRole(['admin','super-admin']))` jako defense-in-depth. |

### Zweryfikowane jako bezpieczne (oba audyty)

- **IDOR niemożliwy** — org wyłącznie z `TenantFeature::currentTenant()` (Filament tenant → request attr → session), nigdy z inputu requestu.
- **Izolacja audit logu** — `OrganizationLifecycleLog` celowo unscoped (musi przeżyć purge), ale ma **wyłącznie write-path** (`record()`); zero tenant-facing read. Brak FK na `organization_id` = przeżywa `forceDelete` (snapshot `organization_name`/`actor_label`). Append-only (`UPDATED_AT = null`).
- **Brak eskalacji** — flaga `closure_requested_at` nie zmienia `lifecycle_state` ani nie nadaje uprawnień; realny offboarding pozostaje za akcją super-admina (`EnsureSuperAdmin` middleware + `->authorize()`).
- **Mail header injection N/D** — Symfony Mailer sanityzuje nagłówki; URL z integer PK, nie ze sluga.
- **DoS/spam** — atomowy guard `closure_requested_at !== null` ogranicza do jednego wniosku do czasu `clearClosureRequest`.
- **PII w mailu** — tylko imię/email wnioskującego + nazwa/slug org (proporcjonalne, wewnętrzne, Art. 6(1)(b)).

---

## LC-9 — Last-mile closure (Faza 5.6b)

- **Severity wejściowe:** 🟡 2 MEDIUM + 🟢 1 LOW (wszystkie naprawione przed mergem). Brak CRITICAL/HIGH.
- **OWASP/RODO:** A04 Insecure Design (tenant-scoping bleed) · A09 Logging Failures (export download) · A02/RODO minimalizacja (PII w logach)
- **Gdzie:** `app/Support/Settings/SettingsManager.php`, `app/Filament/Platform/Pages/PlatformSettings.php`, `app/Http/Controllers/Platform/OrganizationDataExportController.php`, `app/Jobs/ExportOrganizationDataJob.php`

### Znalezione i naprawione (oba audyty wskazały #1 niezależnie)

| # | Severity | Problem | Naprawa |
|---|----------|---------|---------|
| 1 | 🟡 | **Tenant-scoping bleed** — `PlatformSettings` pisał `account.closure_request_email` przez `SettingsManager::set()`. Stale session `tenant_id` (po wcześniejszej wizycie na subdomenie tenanta) → `BelongsToOrganization` `creating` hook auto-wypełnia `organization_id` → "globalny" zapis ląduje jako tenant-scoped. Email wygląda na ustawiony, ale globalnie pozostaje stary → łańcuch komunikacji offboardingu zepsuty dla wszystkich tenantów. | Nowe `SettingsManager::getGlobal()`/`setGlobal()` — `withoutEvents` (wycisza `creating` hook) + `withoutGlobalScope` + hard `organization_id => null`. PlatformSettings używa wyłącznie ich. Test regresji `test_set_global_ignores_stale_session_tenant_id`. |
| 2 | 🟡 | **Export download bez śladu** — super-admin pobiera ZIP z pełnym PII (PESEL/NIP) bez wpisu w logu (A09). | `OrganizationDataExportController` loguje każde pobranie (`Log::info` + `OrganizationLifecycleLog` `data_export_downloaded`, `via` = signed-url/super-admin-direct, actor, IP). Testy na obu ścieżkach. |
| 3 | 🟢 | **PII w logach aplikacji** — `owner->email` w `Log::info` (job + command) trafia do agregatorów z dłuższą retencją niż 7-dniowy link. | Zamieniono na `owner_id` + boolean `notified yes/no`. |

### Zweryfikowane jako bezpieczne (oba audyty)

- **Path traversal niemożliwy** — `OrganizationDataExportController` ma 3 bariery: prefix `exports/org-{id}/`, odrzucenie `..`, root-jail Flysystem. Signed URL HMAC-uje wszystkie parametry (zmiana `organization` lub `file` łamie podpis). Super-admin re-derives prefix z route-bound `$organization->id` → brak cross-org IDOR.
- **Eksport na dysku prywatnym** — `Storage::disk('local')` (`storage/app/private/`), zero symlinka do `public/`. Stream przez PHP.
- **Audit log resource read-only** — `canCreate/canEdit/canDelete/canDeleteAny = false`, `bulkActions([])`, tylko `ViewAction`; super-admin gated; `context` JSON HTML-escaped przez Filament (brak stored XSS).
- **Suspended 503 = parytet z Closed 410** — ten sam regex slug-guard przed DB, ta sama klasa ujawnienia nazwy org, brak nowej enumeracji subdomen. `{{ }}` auto-escape w widoku.
- **Offboarding** — `hasRole('super-admin')` guard przed jakąkolwiek zmianą stanu; transakcja commituje przed dispatchem jobów.

---

## LC-10 — Suspend/reactivate/closed audit trail gap (2026-07-05, multi-agent security review)

- **Severity wejściowe:** 🟠 HIGH (brak jakiegokolwiek śladu audytowego dla zawieszenia/reaktywacji/zamknięcia najemcy).
- **Gdzie:** `app/Filament/Platform/Resources/OrganizationResource.php` (`suspend`/`reactivate`), `app/Console/Commands/FinalizeClosingOrganizationsCommand.php` (`closed`)

### Problem

`suspend`/`reactivate` w `OrganizationResource` mutowały `lifecycle_state` bez żadnego wpisu w `OrganizationLifecycleLog` — jedyny ślad to `updated_at`. Niespójne z sąsiednimi akcjami w tym samym pliku (`initiateClosing`, `clearClosureRequest`), które już logują. `FinalizeClosingOrganizationsCommand` (finalizacja zamknięcia po okresie karencji — nieodwracalne, wyzwala czyszczenie PII) miało ten sam brak — `OrganizationLifecycleLogResource` już miał gotową etykietę `'closed' => 'Zamknięte'` w UI, ale żadne zdarzenie o tej nazwie nigdy nie powstawało.

### Rozwiązanie

Dodano `OrganizationLifecycleLog::record()` do wszystkich trzech miejsc — `suspend`/`reactivate` z `auth()->user()` jako aktorem (panel `/platform`, zawsze super-admin), `closed` z `actor = null` (zdarzenie systemowe ze scheduled command, model już wspierał `?User $actor = null`). Regresja: `tests/Feature/Platform/OrganizationLifecycleAuditActionsTest.php`, `tests/Feature/Console/FinalizeClosingOrganizationsTest.php`.

---

*Rejestr utworzony 2026-06-30. Powiązane: `app/docs/features/tenant-lifecycle.md`, `app/docs/features/orders-security-hardening.md`, `app/docs/features/analytics-event-tracking.md`, `.claude/rules/ci-cd-troubleshooting.md`, `.claude/rules/models.md`, `.claude/rules/notifications.md`.*
