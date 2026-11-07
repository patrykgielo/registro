<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Everything derived from the JSON file (field names are attacker-chosen keys, external_id,
 * paths, product and category names) is untrusted when it is printed: a terminal escape
 * sequence can rewrite the screen, a `<error>` tag restyles the output. Strip every C0/C1
 * control character (none is kept — not even \n or \t) and escape the formatter's tags.
 */
final class ConsoleText
{
    public static function safe(?string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', '', (string) $text) ?? '';

        return OutputFormatter::escape($text);
    }
}
