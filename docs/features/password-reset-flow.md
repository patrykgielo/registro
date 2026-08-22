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

## Znane, NIENAPRAWIONE: mail resetu omija cały system mailowy tenanta

`App\Notifications\PasswordResetNotification` istnieje, jest napisane, używa `EmailService`
i szablonu `TemplateKey::PASSWORD_RESET` — i **nigdy nie jest wysyłane**:

- `User` nie nadpisuje `sendPasswordResetNotification()`
- nikt nie rzuca `PasswordResetRequested`, więc listener w `AppServiceProvider:337` nigdy nie biegnie
- brak `ResetPassword::toMailUsing()` / `createUrlUsing()`

Wychodzi standardowe powiadomienie Laravela: temat **„Reset Password Notification"**, po angielsku
(`lang/pl` nie tłumaczy tego stringa), kanałem `mail` — czyli z pominięciem `EmailService`
(brak logowania w `email_sends`, brak deduplikacji i ponowień), z pominięciem `EmailTemplate`
(brak brandingu tenanta) i z pominięciem ustawień SMTP tenanta.

Skutek produktowy: tenant widzi w panelu edytowalny szablon „Reset hasła", zmienia go — i nic się
nie dzieje. Przy obietnicy whitelabelu to jest wada, nie kosmetyka: klient wypożyczalni dostaje
angielskiego maila z platformy, a nie polskiego z firmy, u której wypożycza.

**Naprawa nie jest jednolinijkowa** i celowo nie została zrobiona razem z 404. Podpięcie samego
listenera przełączyłoby wysyłkę na ścieżkę, która nigdy nie biegła na produkcji — trzeba wtedy
zweryfikować budowanie linku w tamtym powiadomieniu (jest `ShouldQueue`, patrz pułapka wyżej),
treść szablonu w obu językach i zachowanie przy niedostępnym SMTP tenanta.

## Ścieżka operatorska, gdy wszystko zawiedzie

```bash
php artisan registro:password-setup-link <email> --force
```

Link idzie na **stdout**, żeby zepsuty transport pocztowy nie odciął dostępu. `--force` jest
wymagane dla konta, które ma już hasło — jego brak jest celowy, bo taki link pozwala ustawić nowe
hasło bez znajomości starego.
