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

            $properties = null;
            if (! empty($event['properties']) && is_array($event['properties'])) {
                $properties = json_encode($event['properties']);
            }

            $rows[] = [
                'organization_id' => $this->serverProps['organization_id'],
                'user_id' => $this->serverProps['user_id'],
                'session_id' => Str::substr((string) ($this->serverProps['session_id'] ?? ''), 0, 64) ?: null,
                'event' => $eventName,
                'url' => Str::substr((string) ($event['url'] ?? ''), 0, 2048) ?: null,
                'referrer' => Str::substr((string) ($event['referrer'] ?? ''), 0, 2048) ?: null,
                'page_type' => Str::substr((string) ($event['page_type'] ?? ''), 0, 50) ?: null,
                'device_type' => Str::substr((string) ($event['device_type'] ?? ''), 0, 20) ?: null,
                'viewport_w' => isset($event['viewport_w']) ? (int) $event['viewport_w'] : null,
                'properties' => $properties,
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                'received_at' => $receivedAt,
            ];
        }

        if (empty($rows)) {
            return;
        }

        DB::table('analytics_events')->insert($rows);
    }
}
