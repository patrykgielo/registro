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
            'events.*.url' => ['nullable', 'string', 'max:2048'],
            'events.*.referrer' => ['nullable', 'string', 'max:2048'],
            'events.*.timestamp' => ['nullable', 'string'],
            'events.*.page_type' => ['nullable', 'string', 'max:50'],
            'events.*.device_type' => ['nullable', 'string', 'max:20'],
            'events.*.viewport_w' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'events.*.properties' => ['nullable', 'array', 'max:20'],
        ]);

        $serverProps = [
            'organization_id' => $tenant->id,
            'user_id' => $request->user()?->id,
            'received_at' => now()->format('Y-m-d H:i:s'),
        ];

        $sessionId = hash(
            'sha256',
            $request->ip()
                .($request->userAgent() ?? '')
                .$tenant->id
                .Carbon::today()->format('Y-m-d')
                .config('app.key')
        );

        $serverProps['session_id'] = $sessionId;

        IngestAnalyticsEventsJob::dispatch($request->input('events'), $serverProps);

        return response()->json(['ok' => true, 'session_id' => $sessionId], 202);
    }
}
