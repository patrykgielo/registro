<?php

declare(strict_types=1);

namespace App\Support\Media;

use enshrined\svgSanitize\Sanitizer;
use Illuminate\Support\Facades\Storage;

/**
 * The one place an uploaded SVG logo is sanitised (DesignHub logo, SystemSettings header/footer logo).
 *
 * Fails closed: content the library refuses (or that comes back empty) is deleted from the disk and
 * the upload is aborted, so no unsanitised copy is ever left publicly reachable.
 *
 * Resolve it from the container (`app(SvgLogoSanitizer::class)`), never `new` it in a caller: the
 * caller tests swap the binding to prove each upload path still goes through here. Not `final` for
 * the same reason (Mockery cannot double a final class).
 */
class SvgLogoSanitizer
{
    public const REJECTED_MESSAGE = 'SVG contains dangerous content';

    /**
     * Sanitise an already-stored SVG in place.
     *
     * @throws \Exception when the SVG is rejected; the stored file is deleted first
     */
    public function sanitizeStoredFile(string $path, string $disk = 'public'): void
    {
        $storage = Storage::disk($disk);

        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);
        $clean = $sanitizer->sanitize((string) $storage->get($path));

        // `false` = parse failure / DTD / non-SVG root. `''` only comes from an empty input, which a
        // logo upload never legitimately is.
        if ($clean === false || trim($clean) === '') {
            $storage->delete($path);

            throw new \Exception(self::REJECTED_MESSAGE);
        }

        $storage->put($path, $clean);
    }
}
