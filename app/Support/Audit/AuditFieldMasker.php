<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Masks known-sensitive field values (PESEL, ID-document numbers) before they
 * leave the application boundary in bulk exports.
 *
 * Encryption-at-rest on AuditLog::old_values/new_values (see EncryptedJsonCast)
 * protects the stored blob, but does nothing once a legitimate super-admin
 * decrypts their own CSV export — this masking is the second layer, reducing
 * blast radius if that export is later leaked/exfiltrated.
 */
class AuditFieldMasker
{
    /**
     * @var array<int, string>
     */
    private const SENSITIVE_FIELDS = [
        'pesel',
        'customer_pesel',
        'signatory_id_number',
        'pickup_person_id_number',
    ];

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public static function mask(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach (self::SENSITIVE_FIELDS as $field) {
            if (! array_key_exists($field, $values) || $values[$field] === null) {
                continue;
            }

            $values[$field] = self::maskValue((string) $values[$field]);
        }

        return $values;
    }

    private static function maskValue(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (strlen($value) <= 4) {
            return str_repeat('*', strlen($value));
        }

        return str_repeat('*', strlen($value) - 4).substr($value, -4);
    }
}
