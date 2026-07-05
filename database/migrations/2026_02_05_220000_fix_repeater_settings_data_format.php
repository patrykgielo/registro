<?php

declare(strict_types=1);

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Fix corrupted Repeater data in settings table.
 *
 * Problem: before_visit_items was stored as nested array [['item1', 'item2']]
 * instead of flat array ['item1', 'item2'].
 *
 * This caused [object Object] display bug in Filament simple Repeater.
 *
 * Incident: 2026-02-05 - staging had corrupted Repeater data.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fix before_visit_items: multiple possible corrupt formats
        $setting = Setting::where('group', 'booking_wizard')
            ->where('key', 'before_visit_items')
            ->first();

        if ($setting && is_array($setting->value)) {
            $value = $setting->value;
            $needsSave = false;

            // Format 1: Doubly nested strings [['item1', 'item2', ...]]
            if (count($value) === 1 && isset($value[0]) && is_array($value[0]) && ! empty($value[0])) {
                $firstInner = reset($value[0]);
                if (is_string($firstInner)) {
                    // Flatten to: ['item1', 'item2', ...]
                    $value = $value[0];
                    $needsSave = true;
                }
            }

            // Format 2: Simple Repeater format [['item' => 'text1'], ['item' => 'text2'], ...]
            if (! empty($value) && is_array($value[0] ?? null) && isset($value[0]['item'])) {
                // Extract 'item' values: ['text1', 'text2', ...]
                $value = array_map(fn ($item) => $item['item'] ?? '', $value);
                $needsSave = true;
            }

            if ($needsSave) {
                $setting->value = $value;
                $setting->save();
            }
        }

        // Fix service_location_types: ensure proper array structure
        $locationSetting = Setting::where('group', 'booking_wizard')
            ->where('key', 'service_location_types')
            ->first();

        if ($locationSetting && is_array($locationSetting->value)) {
            $value = $locationSetting->value;

            // Check if it's wrapped in extra array: [[{...}, {...}]]
            if (count($value) === 1 && isset($value[0]) && is_array($value[0]) && isset($value[0][0])) {
                // Flatten to: [{...}, {...}]
                $locationSetting->value = $value[0];
                $locationSetting->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new \RuntimeException('This migration is a data-only fix and cannot be rolled back safely.');
    }
};
