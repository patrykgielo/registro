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
Każda **nieklientowska** gałąź `LoginController::authenticated()` musi wołać
`IntendedDestination::discard()`, inaczej admin wyląduje w losowym miejscu. Kto pisze do tego
klucza, czyści **oba** — razem z `url.intended_at`.

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
