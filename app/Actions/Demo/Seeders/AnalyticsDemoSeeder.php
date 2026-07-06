<?php

declare(strict_types=1);

namespace App\Actions\Demo\Seeders;

use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsDemoSeeder implements DemoDataSeeder
{
    private const DEVICE_WEIGHTS = [
        'desktop' => 60,
        'mobile' => 32,
        'tablet' => 8,
    ];

    private const VIEWPORT_RANGES = [
        'desktop' => [1024, 1920],
        'mobile' => [360, 430],
        'tablet' => [768, 1024],
    ];

    private const PAGE_TYPES = [
        'homepage' => 30,
        'catalogue' => 25,
        'service' => 20,
        'booking' => 12,
        'cart' => 7,
        'checkout' => 4,
        'confirmation' => 2,
    ];

    private const URL_MAP = [
        'homepage' => ['/'],
        'catalogue' => ['/wypozyczalnia', '/wypozyczalnia/elektronarzedzia', '/wypozyczalnia/sprzet-budowlany', '/wypozyczalnia/ogrodniczy'],
        'service' => ['/wypozyczalnia/elektronarzedzia/wiertarka', '/wypozyczalnia/sprzet-budowlany/rusztowanie', '/wypozyczalnia/ogrodniczy/kosiarka', '/wypozyczalnia/elektronarzedzia/szlifierka'],
        'booking' => ['/rezerwacja', '/rezerwacja/krok-1', '/rezerwacja/krok-2'],
        'cart' => ['/koszyk'],
        'checkout' => ['/zamowienie'],
        'confirmation' => ['/zamowienie/potwierdzenie'],
    ];

    // Scroll milestones: probability that a session reaches each level
    private const SCROLL_REACH_PROBABILITY = [
        25 => 0.72,
        50 => 0.50,
        75 => 0.30,
        90 => 0.15,
        100 => 0.06,
    ];

    public function seed(Organization $org): void
    {
        $rows = [];
        $now = Carbon::now();
        $baseUrl = "https://{$org->slug}.registro.app";

        // Seed 35 days so both "this_week" and "this_month" show data
        for ($daysAgo = 34; $daysAgo >= 0; $daysAgo--) {
            $date = $now->copy()->subDays($daysAgo);
            $isWeekend = in_array($date->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY]);

            // Realistic traffic pattern: weekdays 20-90, weekends 8-35
            $dailyPageViews = $isWeekend
                ? $this->seededRand($org->id, $daysAgo, 8, 35)
                : $this->seededRand($org->id, $daysAgo, 20, 90);

            $sessionCount = max(1, (int) ($dailyPageViews / $this->seededRand($org->id, $daysAgo + 1000, 3, 6)));

            $sessions = [];
            for ($s = 0; $s < $sessionCount; $s++) {
                $sessions[] = hash('sha256', "demo-{$org->id}-{$date->format('Y-m-d')}-{$s}");
            }

            // Distribute page views across sessions
            $viewsPerSession = array_fill(0, $sessionCount, 1);
            $remaining = $dailyPageViews - $sessionCount;
            for ($i = 0; $i < $remaining; $i++) {
                $viewsPerSession[$i % $sessionCount]++;
            }

            foreach ($sessions as $idx => $sessionId) {
                $device = $this->weightedPick(self::DEVICE_WEIGHTS, $org->id + $idx + $daysAgo * 100);
                [$vpMin, $vpMax] = self::VIEWPORT_RANGES[$device];
                $viewportW = $this->seededRand($org->id + $idx, $daysAgo + 200, $vpMin, $vpMax);
                $sessionViews = $viewsPerSession[$idx];

                for ($v = 0; $v < $sessionViews; $v++) {
                    $pageType = $this->weightedPick(self::PAGE_TYPES, $org->id + $idx + $v + $daysAgo * 200);
                    $urlPool = self::URL_MAP[$pageType] ?? ['/'];
                    $url = $baseUrl.$urlPool[($v + $idx) % count($urlPool)];

                    $minuteOffset = $this->seededRand($org->id + $v, $daysAgo + $idx * 10, 0, 1380);
                    $occurredAt = $date->copy()->startOfDay()->addMinutes($minuteOffset)->addSeconds($v * 30);

                    $rows[] = [
                        'organization_id' => $org->id,
                        'user_id' => null,
                        'session_id' => $sessionId,
                        'event' => 'page_viewed',
                        'url' => $url,
                        'referrer' => $v === 0 ? $this->pickReferrer($org->id + $idx + $daysAgo) : null,
                        'page_type' => $pageType,
                        'device_type' => $device,
                        'viewport_w' => $viewportW,
                        'properties' => null,
                        'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                        'received_at' => $occurredAt->addSeconds(1)->format('Y-m-d H:i:s'),
                    ];

                    // Scroll events for this session (only on content pages)
                    if (in_array($pageType, ['homepage', 'catalogue', 'service', 'article'])) {
                        foreach (self::SCROLL_REACH_PROBABILITY as $milestone => $probability) {
                            $roll = ($this->seededRand($org->id + $milestone, $daysAgo + $idx + $v, 0, 100)) / 100;
                            if ($roll <= $probability) {
                                $scrollAt = $occurredAt->copy()->addSeconds($milestone * 2);
                                $rows[] = [
                                    'organization_id' => $org->id,
                                    'user_id' => null,
                                    'session_id' => $sessionId,
                                    'event' => "scroll_{$milestone}",
                                    'url' => $url,
                                    'referrer' => null,
                                    'page_type' => $pageType,
                                    'device_type' => $device,
                                    'viewport_w' => $viewportW,
                                    'properties' => null,
                                    'occurred_at' => $scrollAt->format('Y-m-d H:i:s'),
                                    'received_at' => $scrollAt->addSeconds(1)->format('Y-m-d H:i:s'),
                                ];
                            }
                        }
                    }
                }
            }
        }

        // Insert in chunks to avoid packet size limits
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('analytics_events')->insert($chunk);
        }
    }

    public function clear(Organization $org): void
    {
        DB::table('analytics_events')
            ->where('organization_id', $org->id)
            ->delete();
    }

    public function hasData(Organization $org): bool
    {
        return DB::table('analytics_events')
            ->where('organization_id', $org->id)
            ->exists();
    }

    private function weightedPick(array $weights, int $seed): string
    {
        $total = array_sum($weights);
        $roll = abs(crc32((string) $seed)) % $total;
        $cumulative = 0;
        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            if ($roll < $cumulative) {
                return (string) $key;
            }
        }

        return array_key_first($weights);
    }

    // Deterministic "random" — same inputs always produce same output
    private function seededRand(int $seed1, int $seed2, int $min, int $max): int
    {
        $hash = abs(crc32("{$seed1}:{$seed2}"));

        return $min + ($hash % ($max - $min + 1));
    }

    private function pickReferrer(int $seed): ?string
    {
        $referrers = [
            null,
            null, // 2x null = higher chance of direct traffic
            'https://www.google.com/',
            'https://www.google.pl/',
            'https://www.facebook.com/',
            'https://l.facebook.com/',
            'https://www.olx.pl/',
        ];

        return $referrers[abs(crc32((string) $seed)) % count($referrers)];
    }
}
