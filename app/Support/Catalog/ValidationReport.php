<?php

declare(strict_types=1);

namespace App\Support\Catalog;

/** Per-product error/warning collector. Index is the 0-based position in the file's `products` list. */
final class ValidationReport
{
    /** @var array<int, array{index: ?int, external_id: ?string, field: string, message: string}> */
    private array $errors = [];

    /** @var array<int, array{index: ?int, external_id: ?string, field: string, message: string}> */
    private array $warnings = [];

    public function error(?int $index, ?string $externalId, string $field, string $message): void
    {
        $this->errors[] = ['index' => $index, 'external_id' => $externalId, 'field' => $field, 'message' => $message];
    }

    public function warning(?int $index, ?string $externalId, string $field, string $message): void
    {
        $this->warnings[] = ['index' => $index, 'external_id' => $externalId, 'field' => $field, 'message' => $message];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<int, array{index: ?int, external_id: ?string, field: string, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<int, array{index: ?int, external_id: ?string, field: string, message: string}> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<int, int> product indexes with at least one error */
    public function failedIndexes(): array
    {
        return array_values(array_unique(array_filter(array_column($this->errors, 'index'), fn ($i) => $i !== null)));
    }

    /** @return array<int, array<int, string>> rows for Command::table() */
    public function rows(array $entries): array
    {
        return array_map(fn (array $e) => [
            $e['index'] === null ? '-' : '#'.$e['index'],
            // field names and external ids come straight from the (untrusted) file
            ConsoleText::safe($e['external_id'] ?? '-'),
            ConsoleText::safe($e['field']),
            ConsoleText::safe($e['message']),
        ], $entries);
    }
}
