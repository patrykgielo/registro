---
paths:
  - "app/Http/Controllers/Auth/**"
  - "app/Http/Middleware/**"
  - "config/auth.php"
  - "config/session.php"
  - ".env.example"
---

# Security Rules

## Authentication

### Password Hashing
- Use bcrypt with 12+ rounds: `BCRYPT_ROUNDS=12`
- Never store plaintext passwords

### Session Security
- Enable encryption: `SESSION_ENCRYPT=true`
- Use secure cookies: `SESSION_SECURE_COOKIE=true` (production)
- Regenerate on login: `session()->regenerate()`

### Rate Limiting
```php
// Auth routes - strict limiting
Route::middleware(['throttle:5,1'])->group(function () {
    Auth::routes();
});
```

## Input Validation

### Never Trust User Input
```php
// BAD
$user = User::find($request->user_id);

// GOOD
$validated = $request->validated();
$user = User::findOrFail($validated['user_id']);
$this->authorize('view', $user);
```

### SQL Injection Prevention
```php
// BAD
DB::select("SELECT * FROM users WHERE email = '$email'");

// GOOD
DB::select("SELECT * FROM users WHERE email = ?", [$email]);
User::where('email', $email)->first(); // Best: Eloquent
```

## Mass Assignment

### Guarded Fields
Always guard sensitive fields in models:
```php
protected $guarded = [
    'id',
    'is_admin',
    'email_verified_at',
    'remember_token',
    'deletion_token',
];
```

## File Upload Security

### SVG Files - CRITICAL XSS RISK

SVG files can contain embedded JavaScript (`<script>` tags).

```php
// ❌ NIEBEZPIECZNE - SVG bez sanityzacji
FileUpload::make('logo')
    ->acceptedFileTypes(['image/svg+xml'])  // XSS vector!

// ✅ BEZPIECZNE - SVG z sanityzacją
use enshrined\svgSanitize\Sanitizer;

->saveUploadedFileUsing(function (TemporaryUploadedFile $file) {
    $path = $file->storePublicly('logos', 'public');

    if ($file->getMimeType() === 'image/svg+xml') {
        $storage = Storage::disk('public');
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $cleanSvg = $sanitizer->sanitize($storage->get($path));
        if ($cleanSvg === false) {
            $storage->delete($path);
            throw new \Exception('SVG contains dangerous content');
        }
        $storage->put($path, $cleanSvg);
    }
    return $path;
})
```

### Path Traversal Prevention

```php
// ❌ NIEBEZPIECZNE - brak walidacji
Storage::url($userProvidedPath);

// ✅ BEZPIECZNE - walidacja ścieżki
private function validateFilePath(string $path): ?string
{
    if (str_starts_with($path, '/')) return null;           // Absolute
    if (str_contains($path, '../')) return null;            // Traversal
    if (str_contains($path, 'livewire-tmp')) return null;   // Not finalized
    if (!Storage::disk('public')->exists($path)) return null;
    return $path;
}
```

### Magic Bytes Validation

```php
$magicBytes = file_get_contents($file->getRealPath(), false, null, 0, 8);
$validSignatures = [
    "\x89PNG\x0D\x0A\x1A\x0A",  // PNG
    "RIFF",                      // WebP
    "\xFF\xD8\xFF",              // JPEG
];
```

## OWASP Top 10 Checklist

- [ ] A01: Broken Access Control - Use Policies
- [ ] A02: Cryptographic Failures - Encrypt sensitive data
- [ ] A03: Injection - Use parameterized queries
- [ ] A04: Insecure Design - Rate limiting enabled
- [ ] A07: Auth Failures - 2FA for admins (recommended)
- [ ] **A08: Software and Data Integrity Failures** - SVG sanitization, magic bytes
