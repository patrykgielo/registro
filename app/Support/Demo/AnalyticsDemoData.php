<?php

declare(strict_types=1);

namespace App\Support\Demo;

use Carbon\Carbon;

/**
 * Returns pre-computed fake analytics data for demo/preview mode.
 * No DB writes — flag on = fake data, flag off = real queries.
 */
class AnalyticsDemoData
{
    // Shared session pool — same IDs used across all methods for coherence
    private const SESSION_POOL = [
        'sess_a3f2', 'sess_b7c1', 'sess_d9e4', 'sess_f1a8', 'sess_g5b3',
        'sess_h2d6', 'sess_i8f0', 'sess_j4c7', 'sess_k6e9', 'sess_l0a2',
        'sess_m3b5', 'sess_n7d8', 'sess_o1f4', 'sess_p9g6', 'sess_q2h1',
    ];

    private const PAGE_VIEWS_BY_PERIOD = [
        'today' => 47,
        'this_week' => 312,
        'this_month' => 1248,
        'last_month' => 1087,
    ];

    private const SESSIONS_BY_PERIOD = [
        'today' => 14,
        'this_week' => 89,
        'this_month' => 315,
        'last_month' => 274,
    ];

    private const UNIQUE_USERS_BY_PERIOD = [
        'today' => 3,
        'this_week' => 21,
        'this_month' => 68,
        'last_month' => 54,
    ];

    /**
     * @return array{page_views: int, unique_sessions: int, unique_users: int, avg_session_events: float}
     */
    public function getKpiData(string $period): array
    {
        $pageViews = self::PAGE_VIEWS_BY_PERIOD[$period] ?? 312;
        $sessions = self::SESSIONS_BY_PERIOD[$period] ?? 89;
        $users = self::UNIQUE_USERS_BY_PERIOD[$period] ?? 21;
        $totalEvents = (int) ($pageViews * 2.4); // scroll events ~1.4x page views

        return [
            'page_views' => $pageViews,
            'unique_sessions' => $sessions,
            'unique_users' => $users,
            'avg_session_events' => $sessions > 0 ? round($totalEvents / $sessions, 1) : 0.0,
        ];
    }

    /**
     * @return array{series: list<array{name: string, data: list<int>}>, categories: list<string>}
     */
    public function getChartData(string $period): array
    {
        [$from, $to] = $this->periodToRange($period);
        $days = (int) $from->diffInDays($to) + 1;

        // Base traffic profile: rises mid-week, dips on weekends
        $basePattern = [0.6, 0.85, 1.0, 0.95, 0.9, 0.45, 0.35]; // Mon–Sun

        $categories = [];
        $views = [];
        $dailyBase = (int) ((self::PAGE_VIEWS_BY_PERIOD[$period] ?? 312) / max(1, $days));

        $current = $from->copy();
        for ($i = 0; $i < $days; $i++) {
            $categories[] = $current->format('d.m');
            $dow = (int) $current->dayOfWeek; // 0=Sun
            $patternIdx = $dow === 0 ? 6 : $dow - 1;
            $multiplier = $basePattern[$patternIdx];
            // Add slight variation per day using deterministic offset
            $variation = 1 + (($i * 37 + 13) % 21 - 10) / 100;
            $views[] = max(0, (int) round($dailyBase * $multiplier * $variation));
            $current->addDay();
        }

        return [
            'series' => [['name' => 'Odsłony', 'data' => $views]],
            'categories' => $categories,
        ];
    }

    /**
     * @return array{25: int, 50: int, 75: int, 90: int, 100: int}
     */
    public function getScrollDepth(string $period): array
    {
        $sessions = self::SESSIONS_BY_PERIOD[$period] ?? 89;

        return [
            '25' => (int) round($sessions * 0.72),
            '50' => (int) round($sessions * 0.50),
            '75' => (int) round($sessions * 0.30),
            '90' => (int) round($sessions * 0.15),
            '100' => (int) round($sessions * 0.06),
        ];
    }

    /**
     * @return list<array{url: string, views: int, sessions: int}>
     */
    public function getTopPages(string $period): array
    {
        $total = self::PAGE_VIEWS_BY_PERIOD[$period] ?? 312;

        $pages = [
            ['path' => '/',                                              'share' => 0.28],
            ['path' => '/wypozyczalnia',                                 'share' => 0.22],
            ['path' => '/wypozyczalnia/elektronarzedzia',                'share' => 0.12],
            ['path' => '/wypozyczalnia/elektronarzedzia/wiertarka',      'share' => 0.09],
            ['path' => '/wypozyczalnia/sprzet-budowlany',                'share' => 0.08],
            ['path' => '/wypozyczalnia/sprzet-budowlany/rusztowanie',    'share' => 0.07],
            ['path' => '/wypozyczalnia/ogrodniczy',                      'share' => 0.05],
            ['path' => '/rezerwacja',                                    'share' => 0.04],
            ['path' => '/koszyk',                                        'share' => 0.03],
            ['path' => '/zamowienie',                                    'share' => 0.02],
        ];

        $sessions = self::SESSIONS_BY_PERIOD[$period] ?? 89;
        $result = [];

        foreach ($pages as $page) {
            $views = (int) round($total * $page['share']);
            $result[] = [
                'url' => 'https://demo.registro.app'.$page['path'],
                'views' => $views,
                'sessions' => (int) round($sessions * $page['share'] * 0.85),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{device: string, count: int}>
     */
    public function getDeviceBreakdown(string $period): array
    {
        $total = self::PAGE_VIEWS_BY_PERIOD[$period] ?? 312;

        return [
            ['device' => 'desktop', 'count' => (int) round($total * 0.60)],
            ['device' => 'mobile',  'count' => (int) round($total * 0.32)],
            ['device' => 'tablet',  'count' => (int) round($total * 0.08)],
        ];
    }

    /**
     * @return list<array{page_type: string, views: int}>
     */
    public function getPageTypeDistribution(string $period): array
    {
        $total = self::PAGE_VIEWS_BY_PERIOD[$period] ?? 312;

        return [
            ['page_type' => 'homepage',     'views' => (int) round($total * 0.28)],
            ['page_type' => 'catalogue',    'views' => (int) round($total * 0.22)],
            ['page_type' => 'service',      'views' => (int) round($total * 0.21)],
            ['page_type' => 'booking',      'views' => (int) round($total * 0.12)],
            ['page_type' => 'cart',         'views' => (int) round($total * 0.07)],
            ['page_type' => 'checkout',     'views' => (int) round($total * 0.06)],
            ['page_type' => 'confirmation', 'views' => (int) round($total * 0.04)],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodToRange(string $period): array
    {
        return match ($period) {
            'today' => [Carbon::today(), Carbon::now()],
            'this_week' => [Carbon::now()->startOfWeek(), Carbon::now()],
            'last_month' => [
                Carbon::now()->subMonthNoOverflow()->startOfMonth(),
                Carbon::now()->subMonthNoOverflow()->endOfMonth(),
            ],
            default => [Carbon::now()->startOfMonth(), Carbon::now()],
        };
    }
}
