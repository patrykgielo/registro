<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use RuntimeException;

/** Loads and structurally checks the import file (size, JSON, schema_version, products list). */
final class CatalogDocument
{
    public const SCHEMA_VERSION = 1;

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_PRODUCTS = 500;

    /**
     * @return array{schema_version: int, products: array<int, mixed>, sha256: string, bytes: int}
     *
     * @throws RuntimeException
     */
    public static function load(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Plik nie istnieje lub jest nieczytelny: {$path}");
        }

        $bytes = (int) filesize($path);

        if ($bytes > self::MAX_BYTES) {
            throw new RuntimeException('Plik większy niż '.(self::MAX_BYTES / 1024 / 1024).' MB.');
        }

        $raw = (string) file_get_contents($path);

        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Nieprawidłowy JSON: '.$e->getMessage());
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Korzeń pliku musi być obiektem {"schema_version":1,"products":[...]}.');
        }

        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new RuntimeException('Nieobsługiwane schema_version (wymagane: '.self::SCHEMA_VERSION.').');
        }

        $products = $data['products'] ?? null;

        if (! is_array($products) || ! array_is_list($products) || $products === []) {
            throw new RuntimeException('"products" musi być niepustą listą.');
        }

        if (count($products) > self::MAX_PRODUCTS) {
            throw new RuntimeException('Za dużo produktów w jednym pliku (max '.self::MAX_PRODUCTS.').');
        }

        $unknown = array_diff(array_keys($data), ['schema_version', 'products']);

        if ($unknown !== []) {
            throw new RuntimeException('Nieznane klucze główne: '.implode(', ', $unknown));
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'products' => $products,
            'sha256' => hash('sha256', $raw),
            'bytes' => $bytes,
        ];
    }
}
