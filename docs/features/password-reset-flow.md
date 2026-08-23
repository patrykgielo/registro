# Reset hasła — co naprawdę się dzieje

Zmierzone 2026-08-22 sondą na prawdziwym przepływie (`POST /password/email` →
`POST /password/reset`) z **prawdziwym** `ResolveTenant` w łańcuchu, nie z test-double'em.
Wcześniejsze opisy tego przepływu w tym repo opierały się na lekturze kodu i były błędne
w obie strony — stąd nacisk na to, co zostało wykonane, a nie przeczytane.

## Przepływ, krok po kroku

1. Użytkownik na subdomenie tenanta (`budowlana.registrolabs.com`) prosi o reset.
   Trasy resetu są opakowane w `ResolveTenant`, z osobnymi kubełkami throttlingu
   (`password-email` 3/min na IP, plus 60-sekundowy cooldown per konto po stronie brokera).
2. Laravel woła `User::sendPasswordResetNotification()`. **Nie jest nadpisane** — wychodzi
   standardowe `Illuminate\Auth\Notifications\ResetPassword`, kanałem `mail`.
3. Powiadomienie **nie jest kolejkowane**, więc renderuje się w żądaniu, gdzie `ResolveTenant`
   już wywołał `URL::forceRootUrl()`. Link trafia na **subdomenę tenanta**. To jest poprawne.
4. Użytkownik ustawia hasło. `ResetsPasswords::resetPassword()` woła `guard()->login()` —
   od tego momentu jest **zalogowany**, dokładnie jak po zwykłym logowaniu.
5. Przekierowanie: `PostAuthDestination::for()`.

## Co było zepsute

`ResetPasswordController` miał `$redirectTo = '/home'`. **Nic nie jest routowane pod `/home`** —
trasa o nazwie `home` to `/`. Zmierzony skutek: hasło ustawione poprawnie, przekierowanie na
`/home`, **HTTP 404**, na własnej subdomenie tenanta, bez drogi powrotnej do panelu.

Nic tego nie logowało. Z perspektywy administratora wypożyczalni reset po prostu nie działał —
a hasło było już zmienione, więc stare też przestawało działać.

Ten sam martwy `/home` siedział w `ConfirmPasswordController` (trasa **zarejestrowana**, więc
osiągalny) i w `VerificationController` (trasy **niezarejestrowane**, `MustVerifyEmail`
zakomentowane w `User` — martwy kod, celowo nietknięty).

## Naprawa

`App\Support\Auth\PostAuthDestination` — jedno miejsce decydujące, dokąd trafia świeżo
uwierzytelniony użytkownik. Logika wyciągnięta bez zmian z `LoginController::authenticated()`,
więc logowanie i reset **nie mogą się rozjechać**. Dowód, że wyciągnięcie niczego nie zmieniło:
`PostLoginRedirectTest` i pozostałe 45 testów autoryzacji przechodzą **bez modyfikacji**.

`ConfirmPasswordController` dostał wyłącznie żywy fallback (`route('home')`), **nie**
`PostAuthDestination` — to brama w trakcie sesji, rozwiązywana przez `redirect()->intended()`,
a `PostAuthDestination` kasuje `url.intended` z założenia i zabrałoby powrót do strony, która
wymusiła potwierdzenie.

## Pułapka: `ShouldQueue` na powiadomieniu o resecie

Link jest poprawny **tylko dlatego**, że powiadomienie renderuje się w żądaniu. Dopisanie
`implements ShouldQueue` wygląda na czystą optymalizację, a przenosi renderowanie na workera
Horizona, gdzie nie ma kontekstu żądania — `url()` spada wtedy na `APP_URL`.

Na dzisiejszym UAT (stack współdzielony, `APP_URL=https://registrolabs.com`) oznaczałoby to link
na domenę główną, gdzie **`/admin/login` zwraca 404**. Zweryfikowane: `registrolabs.com/admin/login`
→ 404, `budowlana.registrolabs.com/admin/login` → 200.

**Zestaw testów tego nie złapie sam z siebie** — `.env.testing` ma `QUEUE_CONNECTION=sync`, więc
kolejkowane powiadomienie i tak wykona się w żądaniu. To ślepa plamka strukturalna, nie luka
w pokryciu. Strażnik pinuje host jawnie:
`PasswordResetRedirectTest::test_the_emailed_link_points_at_the_tenant_subdomain`.

## Mail resetu przechodzi przez system mailowy tenanta (naprawione 2026-08-23)

Do 2026-08-23 `App\Notifications\PasswordResetNotification` istniało, używało `EmailService`
i szablonu `TemplateKey::PASSWORD_RESET` — i **nigdy nie było wysyłane**: `User` nie nadpisywał
`sendPasswordResetNotification()`, nikt nie rzucał `PasswordResetRequested`, brak
`toMailUsing()`/`createUrlUsing()`. Wychodziło standardowe powiadomienie Laravela, temat
**„Reset Password Notification"**, po angielsku, kanałem `mail` — z pominięciem `EmailService`
(brak wiersza w `email_sends`, brak sprawdzenia tłumień, brak ponowień) i `EmailTemplate`
(brak brandingu). Tenant mógł edytować w panelu szablon „Reset hasła", który nigdy nie poszedł.

### Jak jest podpięte

```
User::sendPasswordResetNotification()   → PasswordResetRequested::dispatch()
  listener w AppServiceProvider          → SYNCHRONICZNIE, wciąż w żądaniu
    rozwiązuje resetUrl + appName        → jedyne miejsce, gdzie tenant i host istnieją
      PasswordResetNotification          → ShouldQueue, dostaje je jako dane
        EmailService                     → szablon, email_sends, tłumienia, ponowienia
```

**Powiadomienie nie sięga po nic z otoczenia** i to jest cała jego konstrukcja. Jest kolejkowane,
a worker nie ma żądania, tenanta ani sesji — dwie konkretne awarie z tego wynikają:

- `route()`/`url()` na workerze spadają na `APP_URL`, bo `URL::forceRootUrl()` woła `ResolveTenant`
  wyłącznie w żądaniu. Na dzisiejszym stacku współdzielonym `APP_URL` to domena główna, gdzie
  `/admin/login` zwraca **404**.
- `SettingsManager::appName()` idzie przez `TenantFeature::currentTenant()`, na workerze `null` —
  więc mail niósłby nazwę platformy zamiast nazwy wypożyczalni.

### Pułapka, którą to odsłoniło: niekompletny payload

Martwy kod przekazywał `user_name`, `reset_url`, `token`, a szablon deklaruje
`user_name`, `app_name`, `reset_url`, `expires_in`. `EmailTemplate::substitutePlaceholders()`
zostawia nieznane `{{tokeny}}` **dosłownie**, więc samo podpięcie listenera wysłałoby klientowi
maila z napisem `{{app_name}}`. Brak zmiennej nie jest błędem — jest treścią.

Strażnik: `PasswordResetEmailTest::test_no_placeholder_survives_rendering` asertuje na
wyrenderowanym temacie i treści z `email_sends`, nie na fakcie wysyłki. Dowiedzione:
przywrócenie starego payloadu czerwieni dokładnie ten test i test nazwy tenanta, resztę zostawia
zieloną.

### Co nadal NIE działa — ograniczenie zastane, nie wprowadzone tutaj

**Własne nadpisanie szablonu przez tenanta nadal się nie stosuje.**
`EmailTemplate::resolveActive()` ustala tenanta z kontekstu otoczenia, więc na workerze widzi
tylko szablony globalne — jego własny docblock nazywa to świadomie przyjętym ograniczeniem dla
**każdego** kolejkowanego powiadomienia, nie tylko tego. Mail dostaje więc szablon globalny,
z podstawioną nazwą i adresem tenanta.

Rozwiązanie wymaga przenoszenia kontekstu tenanta do kolejki — zmiana systemowa, dotykająca
wszystkich powiadomień, nie tej jednej ścieżki.

## Ścieżka operatorska, gdy wszystko zawiedzie

```bash
php artisan registro:password-setup-link <email> --force
```

Link idzie na **stdout**, żeby zepsuty transport pocztowy nie odciął dostępu. `--force` jest
wymagane dla konta, które ma już hasło — jego brak jest celowy, bo taki link pozwala ustawić nowe
hasło bez znajomości starego.
