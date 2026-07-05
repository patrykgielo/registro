<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Encrypts array/JSON attributes at rest, while transparently reading rows
 * written before encryption was introduced (plaintext JSON).
 *
 * Used by AuditLog::old_values/new_values — the column is a `longText` (not
 * a native MySQL `json` column) precisely because encrypted payloads are
 * opaque strings, not valid JSON.
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|null>
 */
class EncryptedJsonCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($value), true);
        } catch (DecryptException) {
            // Can't distinguish here between "legacy row written before encryption was
            // introduced" (expected, harmless) and "corrupted ciphertext / botched key
            // rotation / tampering" (a real problem — for an audit log, silently losing
            // data with zero signal defeats its tamper-evidence purpose). Log the record
            // identity (never the value) so a genuine failure is at least discoverable.
            Log::warning('AuditLog: failed to decrypt encrypted JSON attribute, falling back to legacy plaintext parse.', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'attribute' => $key,
            ]);

            $decoded = json_decode($value, true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString(json_encode($value));
    }
}
