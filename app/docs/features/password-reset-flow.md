# Reset hasła — jedna ścieżka dla klienta, admina i super-admina

Jedna ścieżka dla wszystkich ról (`routes/web.php`: `password.request`, `password.email`,
`password.reset`, `password.update`), pod `ResolveTenant`:

1. `ForgotPasswordController` → broker Laravela → `User::sendPasswordResetNotification()` →
   zdarzenie `PasswordResetRequested`.
2. Listener w `AppServiceProvider` liczy **w żądaniu** link (host tenanta) i nazwę marki, po czym
   wysyła `PasswordResetNotification` (kolejka `emails`) → `EmailService::sendFromTemplate('password-reset')`
   → wiersz w `email_sends`, szablon, supresje, ponawianie.
3. `ResetPasswordController` ustawia hasło, loguje użytkownika i kieruje przez `PostAuthDestination`:
   super-admin → `/platform`; admin/staff → `/admin` (na hoście tenanta) albo subdomena pierwszej organizacji
   (z domeny głównej); klient → `IntendedDestination`/`CustomerLandingUrl`.

Nic w tym przepływie nie zakłada klienta; rola decyduje dopiero o miejscu docelowym.

## Wejście z paneli (ClickUp 86cbb28m7, 2026-10-04)

Przepływ istniał, brakowało **wejścia**: `AdminPanelProvider` / `PlatformPanelProvider` wołają `->login()`
bez linku. Teraz oba rejestrują `renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, …)`
(Filament v4: `Filament\View\PanelsRenderHook`, renderowany przez `Login::content()`), który wstawia
`resources/views/filament/auth/forgot-password-link.blade.php` — link `route('password.request')` z kluczem
`account.login.forgot` (ten sam, co na loginie klienta).

- **Host:** `route()` liczy się przy renderze strony. Panel admina ma `ResolveTenant` w middleware
  (`URL::forceRootUrl` na hosta tenanta), więc link idzie na `demo.domena/password/reset`, nie na root.
  Panel platformy działa na domenie głównej i tam zostaje.
- **Panel klienta:** brak — klient loguje się przez `/login` (`auth/login.blade.php`), który ma ten link od zawsze.

### Dlaczego NIE `->passwordReset()` Filamenta

`RequestPasswordReset` tworzy własne `Filament\Auth\Notifications\ResetPassword` bezpośrednio
(`app(ResetPasswordNotification::class, …)`), z pominięciem `User::sendPasswordResetNotification()`.
Skutek: mail poza `EmailService` (brak `email_sends`, supresji, ponawiania), poza szablonem
`password-reset` edytowalnym przez tenanta, poza brandingiem i naszymi tłumaczeniami — dokładnie to,
co `PasswordResetEmailTest` zamyka od 2026-08. Link do istniejącego przepływu jest mniejszą zmianą.

## Testy

`tests/Feature/Auth/AdminPasswordResetEntryTest.php` — prawdziwy `ResolveTenant`, prawdziwe hosty:
link na loginie admina (host tenanta) i platformy (root), pełny przebieg admina (login → formularz → mail
w `email_sends` → tokenizowany URL → nowe hasło → `/admin`, 200) i super-admina (→ `/platform`).
Link w mailu: `PasswordResetEmailTest`; miejsce docelowe: `PasswordResetRedirectTest`.

Znane, zastane: własna wersja szablonu `password-reset` tenanta nie obowiązuje wysyłkom z kolejki
(`EmailTemplate::resolveActive()` czyta tenanta z otoczenia) — patrz `.claude/rules/auth-redirects.md`.
