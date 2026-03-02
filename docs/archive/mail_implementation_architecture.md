Plan wdrożenia systemu e‑mail dla projektu paradocks
1. Wprowadzenie

Repozytorium patrykgielo/paradocks zawiera świeżą instalację Laravel 12 z panelem administracyjnym Filament 3 oraz podstawowym modelem domenowym do obsługi wizyt (Appointment) i użytkowników (User) z rolami spatie/laravel‑permission. W obecnym stanie projekt nie zawiera mechanizmu wysyłki wiadomości e‑mail – brak jest konfiguracji mailera, szablonów, notyfikacji czy kolejek. Ten dokument stanowi kompleksowy plan zaprojektowania i wdrożenia systemu transakcyjnych e‑maili w aplikacji, oparty na standardowych komponentach Laravel i kolejkach. Jako senior Laravel/PHP engineer będziesz odpowiedzialny za implementację, bezpieczeństwo oraz przygotowanie środowiska Docker i panelu administracyjnego. Testy jednostkowe nie są przewidziane w obecnym etapie.

2. Cele i zakres projektu

Obsługa transakcyjnych e‑maili dla kluczowych zdarzeń domenowych:

rejestracja użytkownika (powitanie i ewentualne potwierdzenie adresu),

reset hasła,

utworzenie rezerwacji (Appointment),

zmiana lub anulowanie rezerwacji przez klienta lub pracownika,

przypomnienie o wizycie (24 h i 2 h przed) oraz follow‑up (prośba o opinię),

powiadomienia administracyjne (nowa rezerwacja, nieudana dostawa e‑mail).

Wielojęzyczność (PL/EN) – prototyp – w obecnym etapie przygotuj system tak, aby w przyszłości można było łatwo dodać wersje językowe. Dla prototypu wystarczy, że szablony zawierają tekst w jednym języku (np. PL), a zmienne/labelki będą definiowane w plikach tłumaczeń (resources/lang/pl/mail.php). Przechowywanie wielu wersji w bazie będzie dodane w późniejszym etapie.

Asynchroniczna wysyłka i idempotencja – każdy e‑mail trafia do kolejki Redis/Horizon; system deduplikacji uniemożliwia wysłanie tej samej wiadomości dwukrotnie.

Panel zarządzania w Filament – moduł „Email” w panelu umożliwi adminom zarządzanie szablonami, konfiguracją SMTP (host, port, szyfrowanie, użytkownik, hasło), podglądem logów wysyłek oraz obsługą zdarzeń (bounces, complaints). Dodatkowo należy utworzyć stronę „System Settings”, na której będzie można ustawić globalne wartości, takie jak dane SMTP, parametry kolejek, harmonogram przypomnień oraz adresy e‑mail nadawcy.

Bezpieczeństwo i zgodność z RODO – konfiguracja SPF/DKIM/DMARC, minimalizacja danych w treści e‑maili, retencja logów, obsługa preferencji użytkowników.

Deklaracja dotycząca dostawcy – w pierwszym etapie wykorzystujemy konfigurację SMTP (np. Gmail lub serwer pocztowy zakupiony wraz z domeną). Wszystkie parametry (host, port, szyfrowanie, login, hasło, domyślny nadawca) będą wprowadzane przez administratora z poziomu panelu. Warstwa abstrakcji powinna umożliwiać w przyszłości podmianę na API‑based provider (Postmark, SendGrid). Rekordy DNS (SPF/DKIM/DMARC) należy skonfigurować dla domeny, na której będzie wysyłana poczta.

3. Analiza stanu obecnego repozytorium

Repozytorium paradocks jest inicjalnym szkieletem Laravel z Filament. Nie ma w nim plików konfiguracyjnych mailera ani implementacji notyfikacji. Modele User, Appointment, Service, ServiceAvailability są przygotowane do obsługi systemu rezerwacji. W projekcie znajduje się Filament z domyślną polityką (tylko użytkownicy z e‑mailem w domenie example.com mają dostęp do panelu). Kolejki nie są jeszcze skonfigurowane; w composer.json widać, że używane są narzędzia pail, pint, sail i spatie/laravel-permission. To oznacza, że wdrażając system e‑mail, należy dodać:

plik konfiguracyjny mail.php (jeżeli nie istnieje),

konfigurację pliku .env i panelu ustawień dla połączeń SMTP (host, port, zabezpieczenia TLS/SSL, login, hasło),

definicje eventów i listenerów,

tabelę jobs i uruchomienie Redis/Horizon,

szablony i zasoby Filament,

konfigurację Docker i CI (przygotowanie jobów build i deploy). Testy jednostkowe nie są uwzględnione w tym etapie.

4. Projekt techniczny
   4.1 Zdarzenia domenowe i mapowanie na e‑mail

Stworzyć dedykowane klasowe eventy w App\Events odpowiadające zdarzeniom biznesowym. Każdy event powinien przekazywać dane potrzebne do renderowania treści (np. rezerwację, użytkownika, stare i nowe terminy). Tabela poniżej przedstawia macierz zdarzeń i wymaganych e‑maili (notyfikacje będą asynchroniczne):

Zdarzenie	Klient	Administrator
UserRegistered	Powitanie + (opcjonalnie) link weryfikacyjny.	–
PasswordResetRequested	Link resetu hasła (korzystać z natywnego systemu Laravel).	–
AppointmentCreated	Potwierdzenie rezerwacji (z datą, godziną, nazwą usługi).	Powiadomienie o nowej rezerwacji (możliwość wysyłki zbiorczej).
AppointmentRescheduled	Informacja o zmianie (kto zmienił, stary/nowy termin).	Kopia do admina/staff.
AppointmentCancelled	Potwierdzenie anulowania.	Kopia do admina/staff + powód anulacji.
AppointmentReminder24h, AppointmentReminder2h	Przypomnienie 24h/2h przed wizytą.	–
AppointmentFollowUp	Podziękowanie po wizycie + prośba o opinię.	–
EmailDeliveryFailed (np. bounce)	–	Notyfikacja do zespołu wsparcia z informacją o błędnym adresie.

Do każdego eventu zostanie przypisana klasa Notification, która implementuje ShouldQueue i ShouldBeUnique. Dzięki temu unikniemy duplikatów i cały proces będzie asynchroniczny.

4.2 Warstwa wysyłki i integracja z SMTP

Utworzyć interfejs EmailGatewayInterface w folderze app/Services z metodą send(). W pierwszym etapie implementacją będzie SmtpMailer, który użyje domyślnego mailera Laravel (Mail::mailer('smtp')) do wysyłki. W kolejnych etapach można dodać implementacje dla API‑based providerów.

Dodać serwis EmailService, który pobiera szablony z bazy (email_templates), renderuje je z użyciem Blade i wstrzykniętych zmiennych, zapisuje metadane wysyłki w tabeli email_sends i deleguje faktyczną wysyłkę do EmailGateway. Serwis generuje instancję Illuminate\Notifications\Messages\MailMessage dla notyfikacji.

W config/mail.php skonfigurować mailera smtp z parametrami host/port/encryption/user/password pobieranymi z email_settings (przechowywanymi w bazie). W .env.example dodaj MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION, MAIL_FROM_ADDRESS, MAIL_FROM_NAME. Panel administracyjny „System Settings” pozwoli uzupełnić te wartości i nadpisze konfigurację w runtime.

W config/queue.php ustawić redis jako domyślny backend, dodać dynamiczną konfigurację w .env (QUEUE_CONNECTION=redis). Zainstalować predis/predis (lub wykorzystać wbudowanego klienta phpredis).

Dodać migracje dla tabel: email_templates, email_sends, email_events, email_suppressions, email_settings (rozszerzona o pola smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, from_name, from_address). Zaprojektować klucz idempotencji (message_key) na bazie ID rekordu i typu zdarzenia.

Webhooky: korzystając z SMTP, nie będzie wbudowanych webhooków dostawcy. Informacje o bounce/complaint będą pochodzić z raportów serwera pocztowego lub „Returned Mail”; w późniejszym etapie można rozważyć integrację z usługą monitorującą dostarczalność.

4.3 Panel Filament i System Settings

Utworzyć w folderze app/Filament/Resources następujące zasoby:

EmailTemplateResource – CRUD z polami key, subject, html, text, variables, active. Dla prototypowej wielojęzyczności można dodać pole language (PL/EN), ale na początek pozostaw jednojęzyczne. Dodaj podgląd renderu i przycisk testu („Wyślij test na adres…”). Waliduj placeholdery.

EmailSendResource – lista wysłanych e‑maili z filtrami (status, data, odbiorca, typ szablonu); pokaż treść i metadane; możliwość ponownej wysyłki.

EmailEventResource – wyświetla zdarzenia (np. bounce) z możliwością filtrowania i oznaczania bounce jako obsłużonego; w przypadku SMTP informacje o bounce mogą być dodawane ręcznie.

EmailSettingsResource – formularz konfiguracji poczty: smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, from_name, from_address, parametry retry/backoff i harmonogram przypomnień. Uprawnienie manage email settings przyznawać tylko administratorom.

EmailSuppressionsResource – lista odbiorców w liście supresji (bounces/complaints) z możliwością usunięcia wpisu.

SystemSettingsResource – ogólna podstrona „System Settings” w panelu Filament, w której można konfigurować globalne ustawienia aplikacji. W kontekście poczty będzie tu formularz SMTP (pola jak wyżej) i parametry kolejek/cron. W przyszłości mogą tu trafić inne konfiguracje aplikacji (strefa czasowa, języki).

Dodaj klasę Policy i zarejestruj w AuthServiceProvider dla każdego zasobu. Skorzystaj z spatie/laravel-permission do definiowania ról (np. admin, support) i przypisz odpowiednie uprawnienia.

4.4 Harmonogram i kolejki

Skonfiguruj Redis oraz Laravel Horizon w docker-compose.yml i config/horizon.php. Utwórz osobne serwisy w Compose: queue (dla php artisan queue:work), scheduler (dla php artisan schedule:run), horizon (dashboard). W dev dołącz mailpit.

W app/Console/Kernel.php zaplanuj zadania: SendReminderEmailsJob (uruchamia się co godzinę, wyszukując rezerwacje 24 h i 2 h przed), SendFollowUpEmailsJob (np. co godzinę, wysyłając follow‑up 24 h po wizycie), oraz SendAdminDigestJob (codzienny raport o nowych rezerwacjach). Każdy job powinien implementować ShouldBeUnique z odpowiednim kluczem (np. datą i identyfikatorem wizyty).

4.5 Bezpieczeństwo i zgodność

DNS – w ramach wdrożenia skonfiguruj rekordy DNS SPF (v=spf1 include:_spf.google.com ~all lub odpowiedni dla serwera SMTP), DKIM (wygenerowane w panelu usługodawcy poczty) oraz DMARC (np. v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com). Rekordy te zapewnią wysoką dostarczalność z wykorzystaniem własnej domeny.

Uwierzytelnianie – włącz weryfikację adresu e‑mail dla użytkowników (MustVerifyEmail) lub użyj powitalnej wiadomości z linkiem weryfikacyjnym (Laravel posiada gotową implementację). Zapewnij, by hasło resetu korzystało z natywnych funkcji password.reminders. W prototypie skup się na wysyłce maili transakcyjnych; moduł weryfikacji można uaktywnić w kolejnych sprintach.

RODO – nie umieszczaj danych wrażliwych w treści e‑maili; zanonimizuj dane w tabelach logów (np. maskuj część adresu). Dodaj retencję logów (np. 90 dni) – można to zaimplementować jako zadanie harmonogramu czyszczącego.

Webhook – weryfikuj podpisy i limituj liczbę żądań (middleware ThrottleRequests); loguj niepoprawne żądania.

4.6 CI/CD (bez testów jednostkowych)

Analiza statyczna – skonfiguruj larastan/phpstan (level max) i pint; dodaj do pipeline CI. Testy jednostkowe nie są w tym etapie konieczne – skoncentruj się na poprawnym działaniu aplikacji ręcznie.

Pipeline – w .github/workflows/ci.yml (jeśli używasz GitHub Actions) skonfiguruj joby: instalacja zależności (z cachowaniem vendor), uruchomienie Larastan i Pint, budowa obrazu Docker oraz ewentualne skany bezpieczeństwa (np. sensiolabs/security-checker). Sekrety (MAIL_USERNAME, MAIL_PASSWORD itd.) przekazuj z GitHub Secrets; nie zapisuj ich w repo.

5. Plan wdrożenia krok po kroku

Konfiguracja projektu – dodaj plik config/mail.php z ustawieniem smtp jako domyślnego mailera oraz config/email_settings.php z polami potrzebnymi do przechowywania konfiguracji SMTP (host, port, encryption, user, password, from_name, from_address, retry/backoff, harmonogramy). Zaktualizuj .env.example o zmienne MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION, MAIL_FROM_ADDRESS, MAIL_FROM_NAME, QUEUE_CONNECTION=redis.

Instalacja bibliotek – dodaj do composer.json: laravel/horizon, predis/predis (jeśli preferujesz), filament/filament (już zainstalowany) oraz ewentualnie pakiety do obsługi SMTP (Laravel używa wbudowanej biblioteki SwiftMailer/Symfony Mailer). Uruchom composer update.

Migracje i modele – utwórz migracje dla tabel email_templates, email_sends, email_events, email_suppressions, email_settings. Utwórz odpowiadające modele Eloquent.

Serwisy i notyfikacje – utwórz interfejs EmailGatewayInterface i implementację SmtpMailer, utwórz EmailService do obsługi szablonów i wysyłki, notyfikacje (UserRegisteredNotification, AppointmentCreatedNotification, itp.) oraz eventy UserRegistered, AppointmentCreated, itd.

Panel Filament – utwórz zasoby EmailTemplateResource, EmailSendResource, EmailEventResource, EmailSuppressionsResource, EmailSettingsResource, SystemSettingsResource z odpowiednimi formularzami, filtrami i podglądem. Skonfiguruj uprawnienia w spatie/laravel-permission.

Kolejki i scheduler – skonfiguruj Redis i Horizon w docker-compose.yml. Utwórz serwisy queue i scheduler. W app/Console/Kernel.php dodaj zadania przypomnień i follow‑up, z wykorzystaniem ShouldBeUnique.

Docker i środowisko – utwórz multi‑stage Dockerfile (wersja dev i prod). W docker-compose.yml zdefiniuj serwisy app, db, redis, queue, scheduler, horizon, mailpit (w dev). Dodaj healthchecki. Upewnij się, że mailpit jest dostępny pod http://localhost:8025 w dev.

Konfiguracja domeny i SMTP – zakup domenę i usługę hostingową z pocztą (np. Google Workspace). W panelu DNS ustaw rekordy SPF i DKIM zgodnie z instrukcją dostawcy. W panelu administracyjnym aplikacji wprowadź dane SMTP (host, port, login, hasło, szyfrowanie). Przetestuj wysyłkę testową z zakładki szablonu.

Przegląd i rollout – wykonaj manualne testy funkcjonalne: rejestracja użytkownika, tworzenie rezerwacji, zmiana terminu, przypomnienia. Sprawdź, czy e‑maile trafiają do Mailpit w dev oraz do realnej skrzynki w środowisku staging/production. Po upewnieniu się, że wszystko działa, opublikuj aplikację i monitoruj dostarczalność.

6. Podsumowanie

Projekt zakłada wprowadzenie kompletnego systemu wysyłki transakcyjnych e‑maili w aplikacji paradocks, budując go na standardowym ekosystemie Laravel (Mailables/Notifications, kolejki, Horizon) i panelu Filament. Dzięki warstwie abstrakcji EmailGateway możesz w przyszłości dowolnie zmienić dostawcę. Zautomatyzowany scheduler zapewni wysyłkę przypomnień i digestów, a panel administracyjny umożliwi zarządzanie szablonami i konfiguracją. Wdrożenie wymaga skonfigurowania AWS SES i rekordów DNS, uruchomienia Redis/Horizon w Dockerze oraz implementacji jobów i testów. Przy zachowaniu powyższego planu uzyskasz skalowalny i bezpieczny system e‑mail gotowy dla aplikacji SaaS.