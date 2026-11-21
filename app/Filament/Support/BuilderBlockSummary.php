<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Builder\Block;
use Illuminate\Support\Str;

/**
 * Gives a Builder's collapsed items a readable header: "<type> — <short summary>".
 *
 * PRESENTATION ONLY. It swaps each block's label for a closure; it never touches a block's name, schema,
 * defaults or dehydration, so what a block saves is exactly what BuilderBlocks defines. It is applied by
 * the resource that wants it (ServiceResource), not baked into BuilderBlocks, so Pages/Posts/Promotions/
 * Portfolio keep their plain type labels.
 *
 * The block picker also asks a block for its label, with NO state - the closure then returns the plain
 * type label, so the "Dodaj blok" grid keeps showing type names only.
 */
final class BuilderBlockSummary
{
    private const SEPARATOR = ' — ';

    private const MAX_LENGTH = 60;

    /**
     * Candidate keys per block type, first non-blank wins. Types not listed use FALLBACK_KEYS.
     *
     * @var array<string, array<int, string>>
     */
    private const KEYS_BY_TYPE = [
        'text_block' => ['content'],
        'image' => ['caption', 'alt'],
        'video' => ['caption', 'url'],
        'quote' => ['quote'],
        'cta' => ['heading', 'description', 'button_text'],
        'two_columns' => ['left_column', 'right_column'],
        'three_columns' => ['column_1', 'column_2', 'column_3'],
        'hero' => ['title', 'subtitle'],
        'content_grid' => ['heading', 'subheading'],
        'feature_list' => ['heading', 'subheading'],
        'cta_banner' => ['heading', 'subheading'],
        'custom_html' => ['html'],
        'service_features' => ['heading'],
    ];

    private const FALLBACK_KEYS = ['heading', 'title', 'content', 'text'];

    /**
     * @param  array<int, Block>  $blocks
     * @return array<int, Block>
     */
    public static function apply(array $blocks): array
    {
        return array_map(function (Block $block): Block {
            $type = $block->getName();
            $typeLabel = (string) $block->getLabel();

            return $block->label(function (?array $state) use ($type, $typeLabel): string {
                $summary = self::summarize($type, $state);

                return $summary === null ? $typeLabel : $typeLabel.self::SEPARATOR.$summary;
            });
        }, $blocks);
    }

    /**
     * @param  array<string, mixed>|null  $data  the block's own data (what the Builder keeps under `data`)
     */
    public static function summarize(string $type, ?array $data): ?string
    {
        if (blank($data)) {
            return null;
        }

        if ($type === 'gallery') {
            $count = is_array($data['images'] ?? null) ? count(array_filter($data['images'])) : 0;

            return $count > 0 ? self::photos($count) : null;
        }

        foreach (self::KEYS_BY_TYPE[$type] ?? self::FALLBACK_KEYS as $key) {
            if (filled($text = self::plainText($data[$key] ?? null))) {
                return Str::limit($text, self::MAX_LENGTH);
            }
        }

        if ($type === 'feature_list' && is_array($features = $data['features'] ?? null)) {
            $first = reset($features);

            if (is_array($first) && filled($text = self::plainText($first['title'] ?? null))) {
                return Str::limit($text, self::MAX_LENGTH);
            }
        }

        // An uploaded image with no caption or alt: the file name is still better than nothing. On an open form
        // FileUpload keeps its state as `[uuid => 'path']` (a not-yet-saved upload is an object - ignored).
        if ($type === 'image' && filled($path = self::firstString($data['image'] ?? null))) {
            return Str::limit(basename($path), self::MAX_LENGTH);
        }

        return null;
    }

    private static function firstString(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) && $item !== '') {
                    return $item;
                }
            }

            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Text of a field's state. Strings may carry HTML; RichEditor keeps its state as a TipTap document
     * (nested arrays whose leaves are `['type' => 'text', 'text' => '...']`) while the form is open.
     */
    private static function plainText(mixed $value): ?string
    {
        if (is_array($value)) {
            // TipTap text nodes are already plain text (a literal "<script>" typed into the editor is text, not
            // markup), so they skip the HTML clean-up below. Blade escapes whatever reaches the header.
            $text = implode(' ', self::tipTapBlocks($value));
        } elseif (is_string($value)) {
            // `<script>` / `<style>` bodies are code, not text: drop them with their contents before stripping tags.
            $value = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $value);
            $text = html_entity_decode(strip_tags(str_replace('<', ' <', $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : $text;
    }

    /**
     * One string per text block: the text nodes of a paragraph are joined with '' ("Super<b>cena</b>" stays
     * "Supercena"), the paragraphs themselves are joined by the caller with a space.
     *
     * @param  array<mixed>  $node
     * @return array<int, string>
     */
    private static function tipTapBlocks(array $node): array
    {
        if (is_string($node['text'] ?? null)) {
            return [$node['text']];
        }

        $children = array_values(array_filter(is_array($node['content'] ?? null) ? $node['content'] : [], 'is_array'));
        $isInlineContainer = array_filter($children, fn (array $child): bool => is_string($child['text'] ?? null)) !== [];

        if ($isInlineContainer) {
            return [implode('', array_merge(...array_map(fn (array $child): array => self::tipTapBlocks($child), $children)))];
        }

        return array_merge([], ...array_map(fn (array $child): array => self::tipTapBlocks($child), $children));
    }

    /** Polish plural: 1 zdjęcie, 2-4 zdjęcia, 5+ (and 12-14, 22-24 are NOT few) zdjęć. */
    private static function photos(int $count): string
    {
        $lastTwo = $count % 100;
        $last = $count % 10;

        $word = match (true) {
            $count === 1 => 'zdjęcie',
            $last >= 2 && $last <= 4 && ($lastTwo < 12 || $lastTwo > 14) => 'zdjęcia',
            default => 'zdjęć',
        };

        return "{$count} {$word}";
    }
}
