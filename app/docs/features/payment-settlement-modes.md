# Metody rozliczenia i wymienność bramki płatniczej — plan

**Status:** Faza 1 (tryb rozliczenia offline) zaimplementowana — `feature/offline-settlement-mode`,
2026-08-16. Fazy 2–4 nadal planem, patrz sekcja 4 niżej. Sekcje 1–3 opisują stan **sprzed** Fazy 1 i
są zachowane jako kontekst historyczny — realny stan po Fazie 1 opisuje sekcja 4.1.
**Data:** 2026-08-16.
**Powód powstania:** przy pierwszej próbie przejścia pełnej ścieżki wypożyczenia na UAT okazało się, że zamówienia nie da się domknąć. Poniżej jest opis stanu faktycznego, podział na fazy i decyzje, które trzeba podjąć **przed** pisaniem kodu.

---

## 1. Co dokładnie odkryto

Na UAT (`registrolabs.com`, tenant `budowlana`) wprowadzono katalog 13 pozycji sprzętu w 7 kategoriach. Katalog działa publicznie, koszyk działa, formularz checkoutu działa. **Zamówienia nie da się opłacić.**

W całym kodzie są **dokładnie dwa** miejsca przestawiające zamówienie na `paid`:

| Miejsce | Ograniczenie |
|---|---|
| `app/Services/Payment/Przelewy24Service.php:200` | wymaga skonfigurowanych poświadczeń P24 |
| `app/Http/Controllers/Dev/FakePaymentController.php:65` | zablokowane przy `APP_ENV=production` |

Nie ma akcji w panelu administratora, nie ma komendy CLI. Panel udostępnia dopiero `confirm`, `mark_in_progress` i `complete` — wszystkie **od stanu `paid` w górę** (`app/Filament/Resources/OrderResource.php:401,419,437`).

**Wniosek: wypożyczalnia przyjmująca gotówkę przy odbiorze albo przelew na konto nie ma jak obsłużyć ani jednego zamówienia do końca.** W tej branży to nie jest przypadek brzegowy — firma wydaje sprzęt osobiście i przy tej samej okazji pobiera kaucję, więc pobranie zapłaty gotówką jest naturalne.

---

## 2. To są trzy różne problemy, nie jeden

Mieszanie ich w jedno zadanie jest głównym ryzykiem tego przedsięwzięcia.

**(A) Brak trybu rozliczenia offline.** Niezależny od jakiegokolwiek dostawcy. Dotyczy klientów, którzy nigdy nie będą chcieli płatności online.

**(B) Brak abstrakcji bramki.** `Przelewy24Service` jest wołany wprost, a nazwa dostawcy jest wbetonowana w schemat bazy. Dotyczy każdego klienta, który wybierze innego dostawcę niż P24.

**(C) Własność konta płatniczego.** Konto u dostawcy należy do **klienta końcowego**, nie do nas. Nie wiemy z góry, czy będzie to P24, PayU, Tpay czy coś innego. To jest ograniczenie modelu biznesowego, nie techniczne.

Kolejność wynika z pilności, nie z wielkości: **(A) zablokuje pierwszego płacącego klienta niezależnie od tego, co wybierze**, więc idzie pierwsze. (B) jest warunkiem koniecznym dla (C).

---

## 3. Stan faktyczny — co jest w kodzie dzisiaj

### 3.1 Brak abstrakcji, mimo że wzorzec w projekcie istnieje

`Przelewy24Service` (`app/Services/Payment/Przelewy24Service.php:21`) to goła klasa. Nie implementuje interfejsu, nie jest rejestrowana w żadnym service providerze — rozwiązywana wyłącznie autowiringiem w `CheckoutController:24` i `WebhookController:14`.

Co gorsza, **nie jest cienkim adapterem transportu**. Zawiera logikę domenową „co się dzieje po zapłacie": tworzenie `Payment`, `lockForUpdate`, `transitionTo('paid')` (`:200`), zapis `paid_at` (`:207`), alerty rekoncyliacyjne (`:203-212`), dispatch zdarzenia `OrderPaid` (`:243`). Każda kolejna metoda płatności musiałaby ten blok zduplikować albo wymusić jego wyjęcie.

**Projekt ma już właściwy wzorzec, dwukrotnie:**

| Domena | Interfejs | Implementacja | Binding |
|---|---|---|---|
| E-mail | `app/Services/Email/EmailGatewayInterface.php:14` | `SmtpMailer.php:19`, `FakeEmailGateway.php:15` | `AppServiceProvider.php:80-89` |
| SMS | `app/Services/Sms/SmsGatewayInterface.php:15` | `SmsApiGateway.php` | `AppServiceProvider.php:91-95` |

Kształt: **interfejs = wyłącznie transport**, **Service = logika domenowa i persystencja**, **binding zależny od środowiska**. Płatności łamią ten wzorzec w obie strony. To jest gotowy szablon do naśladowania, nie projekt od zera.

### 3.2 Schemat bazy blokuje płatności nie-P24

`payments.p24_session_id` jest **NOT NULL i UNIQUE** (`database/migrations/2026_03_26_000005_create_payments_table.php:19`, unikalność z `2026_03_27_000007:12`). Rekord wpłaty gotówkowej nie ma czym tego pola wypełnić. Kontroler dev obchodzi to syntetyczną wartością `'fake-'.$order->id` (`FakePaymentController.php:58`) — i to jest dokładnie ten rodzaj obejścia, którego nie chcemy w kodzie produkcyjnym.

Brakuje kolumn: `method`/`provider`, `recorded_by` (kto przyjął wpłatę), `reference`/`notes` (numer KP, paragonu).

`payments.status` to natywny MySQL-owy enum `['pending','success','failed','refunded']` — dodanie wartości wymaga migracji `ALTER TABLE`.

**`orders` nie ma żadnej kolumny metody płatności.** Dziś `status='paid'` mówi „zapłacone", ale nie „czym". Jedyny pośredni sygnał, `p24_token IS NULL`, jest już zajęty jako marker TTL (patrz 3.3).

### 3.3 Pułapka, która przewraca naiwną implementację trybu gotówkowego

To jest najważniejszy akapit tego dokumentu.

`CartService.php:263` ustawia `expires_at = now + 20 minut` przy tworzeniu zamówienia. Wartość jest **zahardkodowana**, nie pochodzi z konfiguracji ani ustawień tenanta.

Wydłużenie tego okna istnieje, ale jest zawieszone na `p24_token IS NOT NULL` (`app/Models/Order.php:344-357`, `OrderItem.php:115-137`) — czyli na obecności zarejestrowanej transakcji P24. Ścieżka gotówkowa nie ma czym tego pola wypełnić.

Do tego cron `orders:cleanup-expired` chodzi **co pięć minut** (`routes/console.php:115-120`) i anuluje przeterminowane zamówienia **z powiadomieniem klienta** (`OrderService::cleanupExpired()` woła `cancel()` z domyślnym `notify: true`).

**Skutek: zamówienie czekające na zapłatę gotówką przy odbiorze zostałoby anulowane w ciągu ~20–25 minut, klient dostałby maila o anulowaniu, a sprzęt wróciłby do puli.** Dodanie przycisku „oznacz jako opłacone" bez ruszenia TTL nie zadziała — zamówienia po prostu nie doczekają do odbioru.

### 3.4 Nie istnieje żaden przełącznik płatności

Sprawdzone w `SettingsManager`, `SettingSeeder`, `SeedOrganizationDefaults`, stronach ustawień Filamenta oraz w mechanizmach `TenantFeature`/`hasModule`. Zakładka „Checkout" w `SystemSettings.php:1392` zawiera wyłącznie teksty zgód i notatkę o kaucji — zero konfiguracji płatności.

`FEATURE_DEFAULTS` (`app/Models/Organization.php:56-72`) zawiera tylko `vehicles`, `mobile_service`, `service_area`. Nic o płatnościach — ale mechanizm `enableFeature()`/`disableFeature()` (`:271-286`) jest gotowym punktem zaczepienia.

### 3.5 Istniejący precedens, który warto skopiować

`orders.deposit_status` (enum `not_required|pending|collected|returned|partial_return|forfeited`) obsługiwany akcjami panelu `collect_deposit`, `return_deposit`, `forfeit_deposit` (`OrderResource.php:478,500,522`), każda z formularzem zbierającym notatkę.

**To jest dokładnie kształt, którego brakuje dla płatności głównej.** Kaucja — pobierana fizycznie, przy odbiorze, ręcznie odnotowywana — ma w systemie pełną obsługę. Zapłata za wypożyczenie, pobierana w tym samym momencie i w ten sam sposób, nie ma żadnej.

Drugi precedens: `tenant_payments` (`app/Models/TenantPayment.php`) ma `recorded_by`, `paid_at`, `notes`, `period_month` — to najbliższy istniejący w repo wzorzec ręcznie rejestrowanej wpłaty.

---

## 4. Fazy

### Faza 0 — decyzje biznesowe (bez kodu)

Bez odpowiedzi na te pytania każda implementacja będzie zgadywaniem.

1. **Jak długo trzymamy rezerwację czekającą na odbiór?** Dziś 20 minut. Dla gotówki to może być 24 h, 48 h, do końca dnia roboczego albo do daty rozpoczęcia najmu. Odpowiedź decyduje o kształcie TTL i musi być **konfigurowalna per tenant**, bo wypożyczalnia sprzętu i warsztat będą chciały czego innego.
2. **Czy rezerwacja nieopłacona blokuje sprzęt?** Jeśli tak — jak bronimy się przed blokowaniem magazynu przez klientów, którzy się nie pojawią. Jeśli nie — co się dzieje, gdy dwie osoby zarezerwują ten sam sprzęt na ten sam termin i obie przyjdą.
3. **Czy zamówienie gotówkowe wymaga potwierdzenia przez obsługę**, zanim zablokuje sprzęt? To rozwiązuje punkt 2, ale dokłada pracy ręcznej.
4. **Czy klient końcowy widzi wybór metody**, czy tenant ustawia jedną na sztywno.
5. **Czy dopuszczamy zaliczkę** (część online, reszta przy odbiorze). Jeśli tak, to zmienia model danych znacznie mocniej.

### Faza 1 — tryb rozliczenia offline ✅ ZAIMPLEMENTOWANA (2026-08-16)

Cel: klient może zarezerwować sprzęt i zapłacić przy odbiorze; obsługa odnotowuje wpłatę w panelu.

**Decyzje właściciela produktu, wiążące dla tej fazy** (nie renegocjowane w trakcie implementacji):
1. Rezerwacja trzyma sprzęt konfigurowalny czas, domyślnie 48h.
2. Rezerwacja nieopłacona blokuje sprzęt przez ten czas.
3. Bez potwierdzania przez obsługę przed zablokowaniem sprzętu.
4. Metody rozliczenia ustawia tenant; gdy włączy obie, klient wybiera przy zamówieniu.
5. Bez zaliczek — częściowa płatność poza zakresem (kwota w akcji „odnotuj wpłatę" jest jawnie
   wpisywana przez obsługę, bez walidacji zgodności z `total_amount` — świadomie, do rozstrzygnięcia
   w przyszłej fazie jeśli zajdzie potrzeba).

**Co dokładnie zostało zbudowane:**

| Element | Gdzie |
|---|---|
| Ustawienia per tenant (`checkout.settlement_online_enabled` domyślnie `true`, `checkout.settlement_offline_enabled` domyślnie `false`, `checkout.offline_reservation_hold_hours` domyślnie 48, zakres 1–168h) | `SettingsManager::isOnlineSettlementEnabled()`/`isOfflineSettlementEnabled()`/`availableSettlementMethods()`/`offlineReservationHoldHours()`, zakładka Checkout w `SystemSettings.php` |
| Kolumna `orders.settlement_method` (`online`\|`offline`, domyślnie `online` — każde istniejące zamówienie zachowuje znaczenie „poszło przez P24") | `2026_08_16_120000_add_settlement_method_to_orders_table.php` |
| TTL odczepiony od `p24_token` | **Nie wymagało zmiany `Order::scopeExpired()`/`OrderItem::scopeBlockingAvailability()`** — oba scope'y i tak czytają wyłącznie `expires_at` w gałęzi „brak `p24_token`"; wystarczyło, że `CartService::convertToOrder()` zaczął PISAĆ inną wartość `expires_at` zależnie od `settlement_method` (20 min dla online — bez zmian; `offlineReservationHoldHours()` dla offline). Zero ryzyka rozjazdu obu scope'ów, bo żaden z nich w ogóle nie wie o `settlement_method`. |
| `payments.p24_session_id` rozluźnione do `nullable` (nadal UNIQUE); nowe kolumny `method` (`p24`\|`cash`\|`bank_transfer`, domyślnie `p24`), `recorded_by` (FK users, nullOnDelete), `notes` | `2026_08_16_120001_add_offline_settlement_fields_to_payments_table.php` |
| `OrderService::recordOfflinePayment()` — lockForUpdate, guard `status === 'pending_payment'`, tworzy `Payment(status: success)`, `transitionTo('paid')`, `paid_at`, dispatch `OrderPaid` **poza** transakcją (nie wewnątrz, w przeciwieństwie do istniejącego wzorca w `Przelewy24Service` — patrz `.claude/rules/notifications.md`) | `app/Services/Order/OrderService.php` |
| Akcja panelu „Odnotuj wpłatę" (`record_offline_payment`) — kwota/metoda/notatka, widoczna dla `pending_payment` + `settlement_method=offline` | `OrderResource.php` (table) **i** `Pages/EditOrder.php` (header) — zduplikowana świadomie, patrz uwaga niżej |
| Nowy event `OrderAcceptedOffline` + notyfikacja `OrderAcceptedOfflineNotification` (tylko klient) — dispatch od razu po checkoucie offline, **przed** jakąkolwiek płatnością | `app/Events/OrderAcceptedOffline.php`, `app/Notifications/OrderAcceptedOfflineNotification.php` |
| Nowy szablon `order-accepted-offline` (PL+EN) — „zamówienie przyjęte, zapłacisz przy odbiorze", **nie** „zostało opłacone" | seeder (`EmailTemplateSeeder.php`) + migracja produkcyjna (patrz niżej) |
| Rozgałęzienie w `CheckoutController::submit()` — `$order->isOfflineSettlement()` pomija P24 całkowicie, zwraca `redirect()->route('checkout.return', ['order' => $order->id])` | `app/Http/Controllers/CheckoutController.php` |
| `checkout.return` rozszerzone o lookup po `?order=` (obok istniejącego `?sessionId=`), zawsze scoped do `organization_id` + `user_id` zalogowanego — offline order nigdy nie ma `p24_session_id` | `CheckoutController::return()` |
| UI: wybór metody rozliczenia w `checkout/show.blade.php` (tylko gdy tenant włączył obie), ekran „zarezerwowano" w `checkout/return.blade.php`, baner z terminem rezerwacji w `orders/show.blade.php` | Blade — polerowanie UX zostawione `frontend-ui-architect` w kolejnym przeglądzie |

**Dlaczego nie ma migracji do maszyny stanów (`OrderStatusStateMachine`):** `pending_payment → paid`
już istniała jako legalne przejście (P24 webhook jej używa) — `recordOfflinePayment()` woła
dokładnie to samo przejście, więc cały downstream (guard rekoncyliacyjny, kaucja, protokoły) działa
bez zmian.

**Email templates — pułapka, o której trzeba pamiętać przy każdym nowym `TemplateKey`:**
`EmailTemplateSeeder` biegnie tylko raz, przy pierwszym provisioning tenanta
(`ProvisionTenantCommand::runGlobalSeedersOnce()`). Każdy już-działający stack (włącznie z UAT-em)
NIGDY więcej go nie uruchomi — nowy klucz szablonu musi więc dodatkowo trafić do osobnej migracji
danych produkcyjnych (`insertOrIgnore`, `organization_id => null`), inaczej pierwszy offline
checkout na UAT skończy się cichym „template not found" w `failed_jobs`. Zrobione w
`2026_08_16_120002_seed_order_accepted_offline_email_templates.php`, pinowane testem
`OrderAcceptedOfflineEmailTemplateMigrationTest`. Ten sam wzorzec dotyczy `order-handed-over`,
`order-returned`, `rental-return-due-soon`, `rental-return-overdue` (już naprawione wcześniej) —
i, jak odnotowano w tamtych migracjach, `order-paid`/`order-confirmed`/`order-cancelled`/
`admin-new-order`/`rental-cancelled`/`service-area-available` NADAL nie mają odpowiednika
(pre-existing, poza zakresem tej gałęzi).

**Uwaga wykonawcza (potwierdzona w implementacji):** akcje panelu są celowo zduplikowane między
`OrderResource` a `Pages/EditOrder` — zmiana w jednym miejscu bez drugiego daje niespójny panel.
Oba miejsca wołają identyczną logikę z `OrderService::recordOfflinePayment()` — zduplikowany jest
tylko opis akcji Filamentowej (label/ikona/formularz/widoczność), nie logika biznesowa.

**Nieukończone / świadomie odłożone w tej fazie:**
- Adnotacja o metodzie rozliczenia w protokole wydania (`OrderProtocolPdfService`/
  `handover.blade.php`) — z listy rozpoznania, NIE zrobiona w tym PR-ze (protokoły mają własną,
  szerszą logikę uprawnień i wymagają osobnego przeglądu, żeby nie zepsuć istniejącego zachowania).
- UX wyboru metody rozliczenia w `checkout/show.blade.php` jest funkcjonalny, ale nie przeszedł
  przeglądu `frontend-ui-architect` (accessibility, spójność wizualna z resztą formularza).

### Faza 2 — abstrakcja bramki

Cel: dołożenie drugiego dostawcy nie wymaga dotykania kontrolerów ani maszyny stanów.

- `PaymentGatewayInterface` — wyłącznie transport: rejestracja transakcji, weryfikacja notyfikacji. **Bez** logiki domenowej.
- wyjęcie z `Przelewy24Service` bloku „co się dzieje po zapłacie" do wspólnego use-case'u, wołanego przez każdą metodę rozliczenia (online i offline)
- binding w `AppServiceProvider`, wzorem `EmailGatewayInterface`
- `FakePaymentGateway` do testów — zastąpi `FakePaymentController` i jego obejście przez `p24_session_id`
- odklejenie nazwy dostawcy od schematu: `payments.p24_session_id` → `external_reference` + `provider`; to samo dla `orders.p24_*`
- webhook: dziś jedna trasa `POST /webhooks/przelewy24` (`routes/web.php:186-189`, wyjęta z CSRF w `bootstrap/app.php:26`) — potrzebny routing per dostawca

Sprzężeń do rozplątania jest więcej, niż sugeruje nazwa klasy: teksty maili rekoncyliacyjnych, komunikaty w panelu platformy, anonimizacja `webhook_payload`, komentarze w maszynie stanów. Pełna lista w rozpoznaniu z 2026-08-16.

### Faza 3 — wielu dostawców

Dopiero po fazie 2. Wybór drugiego dostawcy powinien wynikać z zapotrzebowania realnego klienta, nie z naszej ciekawości.

### Faza 4 — onboarding konta klienta

Najdalsza i najbardziej niepewna. Patrz sekcja 5 — żaden polski dostawca nie ma samoobsługowego trybu marketplace, każdy wymaga formalnej umowy partnerskiej. Do rozważenia **dopiero** gdy liczba klientów sprawi, że ręczne wklejanie kluczy przestanie się skalować.

---

## 5. Sandbox u dostawców — stan na 2026-08-16

Research na oficjalnej dokumentacji. Rozróżnienie źródeł zachowane.

| Dostawca | Sandbox bez prawdziwej firmy? | Tryb marketplace |
|---|---|---|
| **Przelewy24** | **NIE** — wymaga aktywnego konta produkcyjnego z zaakceptowaną umową | tak, ale dostęp przez osobne zgłoszenie; natychmiastowa aktywacja tylko dla wybranych partnerów |
| **PayU** | **TAK** — publiczne, współdzielone konto testowe działa od razu; własny panel sandbox bez weryfikacji firmy | tak, dwa tryby onboardingu; PayU przejmuje weryfikację AML submerchanta |
| **Tpay** | **TAK** — pełny sandbox, weryfikacja formalna wyłączona, rejestracja mailem | **najbliższy Stripe Connect** — Partner API rejestruje sprzedawcę i opcjonalnie zwraca jego klucze; wymaga umowy partnerskiej |
| **HotPay** | **nie ustalono** — w publicznej dokumentacji technicznej **nie znaleziono** środowiska sandbox ani kart testowych | nie znaleziono |
| **Stripe** (odniesienie) | TAK, w pełni | Connect — samoobsługowy, jedyny taki |

### Uruchomienie sandboxa Przelewy24 — krok po kroku

1. Załóż **konto produkcyjne** na `przelewy24.pl` i zaakceptuj umowę. Sandbox bez tego nie istnieje — potwierdza to strona rejestracji sandboxa, która wprost odsyła do konta produkcyjnego.
2. Panel produkcyjny → **„Moje dane"** → sekcja **„Konto w SANDBOX"** → uruchom.
3. Dane logowania przychodzą **osobnym mailem**.
4. Panel sandbox → **„Moje konto" → „Ustawienia" → „Dane API i konfiguracja"** — tam `merchant_id`, `pos_id`, CRC, klucz raportów. To **osobny zestaw** wartości niż produkcyjny.
5. Endpoint testowy: `https://sandbox.przelewy24.pl/api/v1/...`, metoda `testAccess` do weryfikacji poświadczeń.
6. **Webhooki wymagają publicznego HTTPS.** P24 nie ma odpowiednika `stripe listen` do przekierowania na localhost — potrzebny tunel (ngrok, Cloudflare Tunnel) albo testy na UAT.
7. **Kody testowe BLIK nie są publiczne** — P24 przekazuje je indywidualnie przez formularz kontaktowy po podaniu ID konta.

**Czego nie ustalono:** czy sandbox P24 wygasa przy braku aktywności; czy webhooki REST wymagają dziś whitelisty IP (starsze integracje SOAP wymagały).

### Rekomendacja operacyjna

Jeśli celem jest **przetestowanie ścieżki online bez zakładania konta produkcyjnego** — P24 odpada, a najszybszą drogą jest **Tpay sandbox** albo **publiczne konto PayU**. Wymaga to jednak integracji z innym dostawcą, czyli fazy 2. Dopóki jej nie ma, jedyną drogą do sandboxa P24 jest założenie konta produkcyjnego.

**Przed jakąkolwiek decyzją o HotPay** trzeba u nich bezpośrednio potwierdzić, czy sandbox w ogóle istnieje.

---

## 6. Czego świadomie nie robimy teraz

- **Nie podłączamy sandboxa jako pierwszego kroku.** Weryfikuje jedną integrację, którą i tak trzeba będzie uogólnić, i nie usuwa luki gotówkowej. Odwrotna kolejność znaczy, że pierwsze wdrożenie u klienta zablokuje się na tym samym.
- **Nie luzujemy bramki `APP_ENV` dla `FakePaymentController`.** To obejście płatności na żywym serwerze i sposób, w jaki wycieka na produkcję.
- **Nie budujemy trybu marketplace**, dopóki ręczne wklejenie kluczy klienta wystarcza.

---

## 7. Powiązane

- `app/docs/features/cart-order-system.md` — zaktualizowany przy Fazie 1 (TTL, tabela `payments`)
- `docs/architecture/status-machines.md` — maszyna stanów zamówienia
- `.claude/rules/notifications.md` — wzorzec `notify()`/`event()` poza `DB::transaction()`
- `.claude/rules/migrations.md` — wzorzec migracji danych produkcyjnych dla nowych `email_templates`
- `.claude/rules/` — reguły projektu

**Rozpoznanie stanu kodu i research dostawców: 2026-08-16.** Odniesienia `plik:linia` były prawdziwe w tym dniu i przy zmianach w kodzie mogą się rozjechać — traktuj je jako punkt wejścia, nie jako pewnik.
