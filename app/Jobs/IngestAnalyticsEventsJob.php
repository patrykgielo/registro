<?php

declare(strict_types=1);

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IngestAnalyticsEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const VALID_EVENTS = [
        // Existing tracker events
        'page_viewed',
        'scroll_25', 'scroll_50', 'scroll_75', 'scroll_90', 'scroll_100',
        'exit_intent',
        'rage_click',
        'section_visible',
        'page.time_spent',
        // Phase C — new behavioral events
        'product_viewed',
        'calendar_interacted',
        'add_to_cart',
        'cart_viewed',
        'form_field_focused',
        'form_abandoned',
        'back_navigation',
        // Server-side funnel events (dispatched via AnalyticsEventDispatcher)
        'cart.abandoned',
        'checkout.started',
        'checkout.submitted',
        'order.completed',
    ];

    public int $tries = 3;

    public int $backoff = 5;

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @param  array{organization_id: int, user_id: int|null, session_id: string|null, received_at: string}  $serverProps
     */
    public function __construct(
        private readonly array $events,
        private readonly array $serverProps,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(): void
    {
        $receivedAt = $this->serverProps['received_at'];
        $maxOccurredAt = Carbon::parse($receivedAt);
        // Clamp: events cannot claim to have occurred more than 5 minutes ago
        $minOccurredAt = $maxOccurredAt->copy()->subMinutes(5);

        $rows = [];

        foreach ($this->events as $event) {
            $eventName = Str::substr((string) ($event['event'] ?? ''), 0, 100);

            if ($eventName === '') {
                continue;
            }

            if (! in_array($eventName, self::VALID_EVENTS, true)) {
                continue;
            }

            // Parse client timestamp; clamp to [now-5min, now]
            $occurredAt = $maxOccurredAt->copy();
            if (! empty($event['timestamp'])) {
                try {
                    $parsed = Carbon::parse($event['timestamp']);
                    if ($parsed->lt($minOccurredAt)) {
                        $parsed = $minOccurredAt->copy();
                    } elseif ($parsed->gt($maxOccurredAt)) {
                        $parsed = $maxOccurredAt->copy();
                    }
                    $occurredAt = $parsed;
                } catch (\Throwable) {
                    // Malformed timestamp — use received_at
                }
            }

            $props = ! empty($event['properties']) && is_array($event['properties'])
                ? $event['properties']
                : [];

            $properties = empty($props) ? null : json_encode($props);

            $rows[] = [
                'organization_id' => $this->serverProps['organization_id'],
                'user_id' => $this->serverProps['user_id'],
                'session_id' => Str::substr((string) ($this->serverProps['session_id'] ?? ''), 0, 64) ?: null,
                'anonymous_id' => Str::substr((string) ($this->serverProps['anonymous_id'] ?? ''), 0, 64) ?: null,
                'event' => $eventName,
                'browser' => Str::substr((string) ($this->serverProps['browser'] ?? ''), 0, 100) ?: null,
                'os' => Str::substr((string) ($this->serverProps['os'] ?? ''), 0, 100) ?: null,
                'url' => $this->stripQueryString((string) ($event['url'] ?? '')),
                'referrer' => $this->stripQueryString((string) ($event['referrer'] ?? '')),
                'page_type' => Str::substr((string) ($event['page_type'] ?? ''), 0, 50) ?: null,
                'device_type' => Str::substr((string) ($event['device_type'] ?? ''), 0, 20) ?: null,
                'viewport_w' => isset($event['viewport_w']) ? (int) $event['viewport_w'] : null,
                'properties' => $properties,
                'utm_source' => Str::substr((string) ($props['utm_source'] ?? ''), 0, 255) ?: null,
                'utm_medium' => Str::substr((string) ($props['utm_medium'] ?? ''), 0, 255) ?: null,
                'utm_campaign' => Str::substr((string) ($props['utm_campaign'] ?? ''), 0, 255) ?: null,
                'referrer_domain' => $this->extractDomain((string) ($event['referrer'] ?? '')),
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                'received_at' => $receivedAt,
            ];
        }

        if (empty($rows)) {
            return;
        }

        DB::table('analytics_events')->insert($rows);
    }

    /**
     * Strip query string and fragment from a URL, returning scheme+host+path only.
     * Discards non-http(s) URLs (e.g. javascript:) and relative paths.
     */
    private function stripQueryString(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return null;
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return null;
        }

        $result = ($parsed['scheme'] ?? '').'://'.($parsed['host'] ?? '').(isset($parsed['port']) ? ':'.$parsed['port'] : '').($parsed['path'] ?? '');

        return Str::substr($result, 0, 2048) ?: null;
    }

    private function extractDomain(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
