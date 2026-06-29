<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\IngestAnalyticsEventsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EventTrackingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\Organization|null $tenant */
        $tenant = $request->attributes->get('tenant');

        if (! $tenant) {
            return response()->json(['ok' => false, 'error' => 'tenant_required'], 400);
        }

        $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:30'],
            'events.*.event' => ['required', 'string', 'max:100'],
            'events.*.url' => ['nullable', 'string', 'max:2048', 'starts_with:http://,https://'],
            'events.*.referrer' => ['nullable', 'string', 'max:2048', 'starts_with:http://,https://'],
            'events.*.timestamp' => ['nullable', 'string'],
            'events.*.page_type' => ['nullable', 'string', 'max:50'],
            'events.*.device_type' => ['nullable', 'string', 'max:20'],
            'events.*.viewport_w' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'events.*.properties' => ['nullable', 'array', 'max:20'],
            'events.*.properties.*' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }
                    if (is_array($value) || is_object($value)) {
                        $fail('Property values must be scalar.');

                        return;
                    }
                    if (is_string($value) && mb_strlen($value) > 256) {
                        $fail('Property string value must not exceed 256 characters.');
                    }
                },
            ],
            'events.*.anonymous_id' => ['nullable', 'string', 'max:64'],
        ]);

        $ua = $request->userAgent() ?? '';

        $serverProps = [
            'organization_id' => $tenant->id,
            'user_id' => $request->user()?->id,
            'received_at' => now()->format('Y-m-d H:i:s'),
            'anonymous_id' => substr((string) ($request->input('events.0.anonymous_id') ?? ''), 0, 64) ?: null,
            'browser' => $this->detectBrowser($ua),
            'os' => $this->detectOs($ua),
        ];

        $sessionId = hash(
            'sha256',
            $request->ip()
                .$ua
                .$tenant->id
                .Carbon::today()->format('Y-m-d')
                .config('app.key')
        );

        $serverProps['session_id'] = $sessionId;

        IngestAnalyticsEventsJob::dispatch($request->input('events'), $serverProps);

        return response()->json(['ok' => true, 'session_id' => $sessionId], 202);
    }

    private function detectBrowser(string $ua): ?string
    {
        if ($ua === '') {
            return null;
        }
        if (str_contains($ua, 'Edg/')) {
            return 'Edge';
        }
        if (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')) {
            return 'Opera';
        }
        if (str_contains($ua, 'Chrome')) {
            return 'Chrome';
        }
        if (str_contains($ua, 'Firefox')) {
            return 'Firefox';
        }
        if (str_contains($ua, 'Safari')) {
            return 'Safari';
        }

        return null;
    }

    private function detectOs(string $ua): ?string
    {
        if ($ua === '') {
            return null;
        }
        if (str_contains($ua, 'Android')) {
            return 'Android';
        }
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) {
            return 'iOS';
        }
        if (str_contains($ua, 'Windows')) {
            return 'Windows';
        }
        if (str_contains($ua, 'Mac OS X')) {
            return 'macOS';
        }
        if (str_contains($ua, 'Linux')) {
            return 'Linux';
        }

        return null;
    }
}
