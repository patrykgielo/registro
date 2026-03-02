<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AuditLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

class LogAuthenticationEvents
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
        ];
    }

    public function handleLogin(Login $event): void
    {
        $this->log('login', $event->user->id, [
            'guard' => $event->guard,
            'remember' => $event->remember,
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user) {
            $this->log('logout', $event->user->id, [
                'guard' => $event->guard,
            ]);
        }
    }

    public function handleFailed(Failed $event): void
    {
        // Hash email to prevent enumeration while maintaining audit capability
        $emailHash = hash('sha256', $event->credentials['email'] ?? 'unknown');

        $this->log('login_failed', null, [
            'guard' => $event->guard,
            'email_hash' => substr($emailHash, 0, 16), // First 16 chars only
        ]);
    }

    protected function log(string $event, ?int $userId, array $data): void
    {
        // Use REMOTE_ADDR directly to avoid header spoofing
        // Only trust X-Forwarded-For if behind trusted proxy
        $ip = request()->server('REMOTE_ADDR');

        AuditLog::create([
            'auditable_type' => 'authentication',
            'auditable_id' => $userId ?? 0,
            'event' => $event,
            'old_values' => null,
            'new_values' => $data,
            'user_id' => $userId,
            'ip_address' => $ip,
            'user_agent' => request()->userAgent(),
        ]);
    }
}
