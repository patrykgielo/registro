# Metody rozliczenia i wymienność bramki płatniczej — plan

**Status:** plan, nic z tego nie jest zaimplementowane.
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

### Faza 1 — tryb rozliczenia offline

Cel: klient może zarezerwować sprzęt i zapłacić przy odbiorze; obsługa odnotowuje wpłatę w panelu.

Zakres:
- ustawienie per tenant: dozwolone metody rozliczenia (online / przy odbiorze / obie)
- kolumna metody płatności na `orders`
- konfigurowalny TTL rezerwacji, odczepiony od `p24_token`
- akcja w panelu „odnotuj wpłatę" — wzorowana na `collect_deposit`, z kwotą, metodą, notatką i zapisem kto odnotował
- rekord `Payment` dla wpłaty offline (wymaga zdjęcia `NOT NULL` z `p24_session_id`)
- osobny szablon maila „zamówienie przyjęte — zapłata przy odbiorze"; dzisiejszy `ORDER_PAID` mówi „zostało opłacone", co byłoby nieprawdą
- adnotacja o zapłacie w protokole wydania

Pliki dotknięte — lista z rozpoznania, do weryfikacji przed startem:

| Obszar | Pliki |
|---|---|
| Checkout | `CheckoutController.php:77-86,98-149,154-168`, `SubmitCheckoutRequest.php:25-62` |
| Maszyna stanów | `OrderStatusStateMachine.php:27-64,73-88,96-156` |
| Model | `Order.php:301-309,324-327,344-357`, `OrderItem.php:115-137`, `Payment.php:15-35` |
| Panel | `OrderResource.php:282-316,348-396,397-570`, `Pages/EditOrder.php:25-168` |
| Maile | `AppServiceProvider.php:357-371`, `OrderPaidNotification.php`, `EmailTemplateSeeder.php:351-383` |
| Protokoły | `OrderProtocolPdfService.php:51,60,81,129`, `views/orders/protocols/handover.blade.php` |
| Widoki | `checkout/show.blade.php:1361-1407`, `checkout/return.blade.php`, `orders/show.blade.php`, `orders/index.blade.php` |
| TTL | `CartService.php:263`, `OrderService.php:52-62`, `CleanupExpiredOrders.php`, `routes/console.php:115-120` |
| Migracje | `orders` (metoda), `payments` (`p24_session_id`, `method`, `recorded_by`, enum `status`) |

**Uwaga wykonawcza:** akcje panelu są celowo zduplikowane między `OrderResource` a `Pages/EditOrder` — zmiana w jednym miejscu bez drugiego daje niespójny panel.

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

- `app/docs/features/payment-flow.md`, `checkout-order-flow.md`, `cart-order-system.md` — opisują dziś P24 jako jedyne źródło prawdy; **wymagają aktualizacji przy fazie 1**
- `docs/architecture/status-machines.md` — maszyna stanów zamówienia
- `.claude/rules/` — reguły projektu

**Rozpoznanie stanu kodu i research dostawców: 2026-08-16.** Odniesienia `plik:linia` były prawdziwe w tym dniu i przy zmianach w kodzie mogą się rozjechać — traktuj je jako punkt wejścia, nie jako pewnik.
