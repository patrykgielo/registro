---
paths:
  - "app/Support/Auth/**"
  - "app/Http/Responses/**"
  - "app/Http/Controllers/Auth/**"
  - "app/Providers/Filament/**"
---

# Przekierowania po zalogowaniu

Pełny opis mechanizmu: `app/docs/features/post-login-return.md`. Tutaj wyłącznie rzeczy,
których złamanie nie daje żadnego sygnału.

## NIGDY `url()->previous()` do celu przekierowania

`UrlGenerator::previous()` czyta nagłówek **`Referer` przed sesją**. Obca strona linkująca do
naszego `/login` wyśle `Referer: https://zlosliwy.example/` i to zostanie celem.

```php
$url = url()->previous();                    // ŹLE — Referer wygrywa
$url = $request->session()->previousUrl();   // OK — zapisane serwerowo przez StartSession
```

Strażnik: `PostLoginRedirectTest::test_referer_header_is_never_used_as_a_destination`.

## NIGDY `parse_url()` do sprawdzania, czy adres jest nasz

`parse_url()` i WHATWG URL Standard (czyli każda przeglądarka) **rozjeżdżają się** na wstecznej
kresce w authority:

```php
$u = "http://evil.example\@registro.local/admin/x";
parse_url($u, PHP_URL_HOST);   // "registro.local"  ← PHP: "\" nie jest specjalny
// przeglądarka: host = evil.example, bo "\" == "/"
```

Walidacja oparta o `parse_url()` przepuści to jako „nasz host", a `Location:` wyśle użytkownika
na obcy serwer. Porównuj **prefiks origin**, bez parsowania:

```php
IntendedDestination::isSameOrigin($url, $request);   // str_starts_with($url, origin.'/')
```

Kandydat z `\` albo segmentem `..` jest odrzucany, zanim cokolwiek go dotknie (fail-closed).
Strażniki: `test_backslash_authority_trick_is_rejected`, `test_dot_segment_path_traversal_is_rejected`.

## Tenant do decyzji o celu — z żądania, nie z sesji

```php
$tenant = $request->attributes->get('tenant');       // OK — ustawia ResolveTenant per żądanie
$tenant = TenantFeature::currentTenant();            // ŹLE — ma gałąź na session('tenant_id')
```

`session('tenant_id')` zapisuje się przy każdej anonimowej wizycie, a `SESSION_DOMAIN` bywa
wildcardem między subdomenami — to klasa VULN-003. Strażnik:
`test_poisoned_tenant_id_session_does_not_influence_landing`.

## `url.intended` jest współdzielony z Filamentem

Ten sam klucz czyta panel (`Filament\Auth\Http\Responses\LoginResponse` → `redirect()->intended()`).
Każda **nieklientowska** gałąź `PostAuthDestination::for()` musi wołać
`IntendedDestination::discard()`, inaczej admin wyląduje w losowym miejscu. Kto pisze do tego
klucza, czyści **oba** — razem z `url.intended_at`.

## Cel po uwierzytelnieniu jest JEDEN — `PostAuthDestination`

Logowanie i **reset hasła** kończą w tym samym stanie: `ResetsPasswords::resetPassword()` woła
`guard()->login()`. Rozjechały się mimo to — `ResetPasswordController` miał
`$redirectTo = '/home'`, a **nic nie jest routowane pod `/home`** (trasa o nazwie `home` to `/`).
Zmierzone end-to-end: hasło ustawiane poprawnie, potem 404 na własnej subdomenie tenanta, bez
drogi powrotnej do panelu. Zero sygnału — dla użytkownika reset po prostu „nie działa".

Nowy przepływ kończący się zalogowanym użytkownikiem → `PostAuthDestination::for()`, nigdy własny
`$redirectTo`. **Wyjątek: `ConfirmPasswordController`** — to brama w trakcie sesji, rozwiązywana
przez `redirect()->intended()`; `PostAuthDestination` kasuje `url.intended` z założenia, więc
zabrałoby powrót do strony, która wymusiła potwierdzenie. Tam wyłącznie żywy fallback
(`route('home')`).

`VerificationController` ma ten sam martwy `/home`, ale jego trasy **nie są zarejestrowane**
(`MustVerifyEmail` zakomentowane w `User`) — martwy kod, celowo nietknięty.

## Powiadomienie o resecie NIE MOŻE stać się `ShouldQueue`

Link w mailu jest poprawny (subdomena tenanta) **tylko dlatego**, że Laravelowe
`Illuminate\Auth\Notifications\ResetPassword` nie jest kolejkowane — renderuje się w żądaniu, gdzie
`ResolveTenant` już wywołał `URL::forceRootUrl()`. Dopisanie `implements ShouldQueue` wygląda na
czystą optymalizację, a przenosi renderowanie na workera bez kontekstu żądania: `url()` spada wtedy
na `APP_URL`, czyli **domenę główną, gdzie `/admin/login` zwraca 404**.

Zestaw testów tego nie złapie sam z siebie — `.env.testing` ma `QUEUE_CONNECTION=sync`, więc
kolejkowane powiadomienie i tak wykona się w żądaniu. Strażnik:
`PasswordResetRedirectTest::test_the_emailed_link_points_at_the_tenant_subdomain`.

## Kolejkowane powiadomienie NIE MOŻE sięgać po kontekst otoczenia

`PasswordResetNotification` było martwe do 2026-08-23 (nic nie rzucało `PasswordResetRequested`,
`User` nie nadpisywał `sendPasswordResetNotification()`). Podpięte — ale **wszystkie** wartości
zależne od kontekstu dostaje jako argumenty konstruktora, bo jest `ShouldQueue`:

| wartość | rozwiązywana | co się psuje na workerze |
|---|---|---|
| `resetUrl` | listener, w żądaniu | `route()` spada na `APP_URL` → domena główna, `/admin/login` = 404 |
| `appName` | listener, w żądaniu | `currentTenant()` = `null` → nazwa platformy zamiast wypożyczalni |

Listener `PasswordResetRequested` jest **synchroniczny** i to jedyny powód, dla którego działa —
biegnie wciąż w żądaniu, które poprosiło o reset.

**Brak zmiennej w payloadzie nie jest błędem, tylko treścią maila.**
`EmailTemplate::substitutePlaceholders()` zostawia nieznane `{{tokeny}}` dosłownie; martwy kod
przekazywał trzy zmienne, a szablon deklaruje cztery. Strażnik asertuje na wyrenderowanej treści
z `email_sends`: `PasswordResetEmailTest::test_no_placeholder_survives_rendering`.

Nadal niedziałające i **zastane**: własny szablon tenanta nie stosuje się do wysyłek z kolejki —
`EmailTemplate::resolveActive()` też czyta tenanta z otoczenia (jego docblock nazywa to przyjętym
ograniczeniem dla wszystkich powiadomień). Szczegóły:
`app/docs/features/password-reset-flow.md`.

## Bind do nieistniejącego interfejsu NIE rzuca błędu

`::class` na nieistniejącym namespace kompiluje się bez ostrzeżenia, a `$this->app->bind()`
grzecznie zapamiętuje klucz, którego nikt nigdy nie rozwiąże. Tak `AdminPanelProvider` przez
całą migrację na Filamenta v4 wiązał kontrakt **z v3**, a `App\Http\Responses\LoginResponse`
implementował nieistniejący interfejs i był fizycznie nieładowalny. Nic nie krzyknęło.

Po każdej zmianie namespace'u z vendora sprawdź, że cel istnieje:

```bash
docker compose exec -T app php -r 'var_dump(interface_exists("Pełna\\Nazwa"));'
```

Strażnik: `AdminPanelLoginResponseTest::test_admin_panel_login_response_contract_resolves_to_the_custom_class`
— zweryfikowany mutacją (przywrócenie namespace'u v3 czerwieni go).

## `LoginResponse::toResponse()` zwraca `RedirectResponse|Redirector` — nie zawężaj

Logowanie do panelu to żądanie Livewire, a tam `redirect($url)` zwraca
`Livewire\Features\SupportRedirects\Redirector`. Samo `: RedirectResponse` = HTTP 500 przy **każdym**
logowaniu na `/admin` i `/platform` (rc39, 2026-11-07), a testy wołające `toResponse()` wprost
przechodzą. Test czegokolwiek, co woła strona Filamenta → przez `Livewire::test()`.
Strażnik: `PanelLoginLivewireTest`.

## Reset hasła NIE loguje super-admina (2026-11-07)

**Problem:** `ResetsPasswords::resetPassword()` kończy się `guard()->login()`, a `PostAuthDestination` wysyła
super-admina na `/platform`. **Przyczyna:** reset dowodzi kontroli nad skrzynką, nie kontem — przejęcie skrzynki =
przejęcie platformy, bez starego hasła (ryzyko wzrosło, gdy #286 dał link do resetu na `/platform/login`).
**Fix:** `ResetPasswordController::resetPassword()` dla `super-admin` nie woła `login()` (nie "zaloguj i wyloguj"),
zapisuje `audit_logs` (`password_reset`) i odsyła na login platformy z `passwords.reset_sign_in`.
**To jest złagodzenie, nie domknięcie.** Kto ma skrzynkę, nadal może ustawić hasło i zalogować się — w dwóch
krokach zamiast jednego (login jest throttlowany i zostawia ślad w `audit_logs`). Domyka to dopiero 2FA dla `/platform`
plus mail powiadamiający właściciela platformy o resecie; oba są otwartymi follow-upami.
**Stan w kontrolerze:** Laravel cache'uje instancję kontrolera na trasie (`Route::getController()`), więc pole
ustawione przy jednym resecie żyje do następnego w tym samym procesie — decyzję podejmuj z żądania (rola z
`broker()->getUser()`), nie z właściwości. Strażnik: `test_a_super_admin_reset_does_not_leak_into_the_next_reset_in_the_same_app`.
**Zapobieganie:** nowa ścieżka ustawiająca hasło z tokenu (np. Filament `->passwordReset()`, link zaproszeniowy)
nie może logować tej roli. Komunikat idzie też jako `Filament\Notifications\Notification` — login Filamenta nie
renderuje flasha `status`. Strażnik: `SuperAdminPasswordResetTest`. Ten sam efekt dla `admin`: decyzja produktowa,
nie zrobiona.

## Panel login → reset: link, not `->passwordReset()`

`/admin` and `/platform` login screens link into the EXISTING `password.*` flow via
`renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, …)` (`resources/views/filament/auth/forgot-password-link.blade.php`).
Do NOT switch a panel to Filament's `->passwordReset()`: it instantiates its own `ResetPassword` notification and skips
`User::sendPasswordResetNotification()`, so the mail leaves `EmailService` (no `email_sends`, template, branding, i18n).
Details and guard test: `app/docs/features/password-reset-flow.md`.

## `password.email` answers the same for everyone

`ForgotPasswordController` overrides both response methods: existing, unknown and "throttled" addresses get the same
status, `Location` and `status` key (`passwords.link_requested`). The stock trait was an account-existence oracle for
the whole platform (`users.email` is global). Never re-add `passwords.user` / `throttled` to that endpoint's output.
Residual: synchronous send = timing difference. `PasswordResetEnumerationTest`.
