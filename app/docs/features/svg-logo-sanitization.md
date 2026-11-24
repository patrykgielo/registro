# SVG logo sanitisation (`SvgLogoSanitizer`)

**Status:** stable · **Code:** `app/Support/Media/SvgLogoSanitizer.php` · **Library:** `enshrined/svg-sanitize` ^1.1.1

## What it is

The one implementation that cleans an uploaded SVG logo before it becomes publicly reachable on the
`public` disk. Every logo upload field that accepts `image/svg+xml` calls it after storing the file:

| Caller | Field |
|---|---|
| `DesignHub::processLogoUpload()` | Design Hub logo |
| `SystemSettings` appearance tab, `saveUploadedFileUsing` | `appearance.header_logo` |
| `SystemSettings` appearance tab, `saveUploadedFileUsing` | `appearance.footer_logo` |

```php
$path = $file->storePublicly('settings/logos', 'public');

if ($mimeType === 'image/svg+xml') {
    app(SvgLogoSanitizer::class)->sanitizeStoredFile($path);   // disk defaults to 'public'
}

return $path;
```

## Behaviour

- `new Sanitizer` + `removeRemoteReferences(true)` per call (the library object is stateful).
- Clean result: written back over the stored file, same path.
- **Fail closed:** if the library returns `false` (not XML, DTD/entity declaration, non-SVG root, any
  internal error) **or** the result is empty/whitespace, the stored file is deleted first and
  `\Exception('SVG contains dangerous content')` is thrown. The constant is
  `SvgLogoSanitizer::REJECTED_MESSAGE`.
- A missing stored file is read as `''` and therefore rejected too.

The empty-result rejection was added when the three inline copies were merged (before, `sanitize('')`
returned `''` and an empty file was kept). Real uploads never hit it: a 0-byte file is not detected as
`image/svg+xml` and fails the raster magic-bytes check first.

## Rules for new upload fields

- Resolve it from the container (`app(...)`), never `new SvgLogoSanitizer` and never an inline
  `Sanitizer`: page tests swap the binding with `$this->mock(SvgLogoSanitizer::class)` to prove the
  field goes through it.
- The class is deliberately **not `final`** (Mockery cannot double a final class) and not static.
- Add a test case for the new field. Removing the call from a field must fail a test:
  - `tests/Feature/Filament/SystemSettingsLogoSvgSanitizationTest.php`: drives the real
    SystemSettings page (Livewire `->set('data.appearance.<field>', ...)->call('saveAppearanceSettings')`)
    once per field: dirty SVG stored clean, DTD-entity SVG rejected with nothing stored, mock spy `once()`.
  - `tests/Feature/Security/SvgSanitizationTest.php`: DesignHub real path (library behaviour).
  - `tests/Feature/Security/SvgLogoSanitizerTest.php`: the class contract (reject list incl. empty,
    delete on reject, disk argument) + DesignHub spy.

## Not covered here

The raster magic-bytes check (PNG / WebP / JPEG signatures) in front of it is still duplicated in the
same three callers.
