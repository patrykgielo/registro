<?php

declare(strict_types=1);

namespace App\StateMachines;

use App\Events\OrderCancelled;
use App\Events\OrderConfirmed;
use Asantibanez\LaravelEloquentStateMachines\StateMachines\StateMachine;

class OrderStatusStateMachine extends StateMachine
{
    public function recordHistory(): bool
    {
        return true;
    }

    public function defaultState(): ?string
    {
        return 'pending_payment';
    }

    public function transitions(): array
    {
        return [
            // pending_payment → paid (P24 webhook success)
            // pending_payment → cancelled (TTL expired or user cancel)
            'pending_payment' => ['paid', 'cancelled'],

            // paid → confirmed (admin action)
            // paid → cancelled (admin action)
            'paid' => ['confirmed', 'cancelled'],

            // confirmed → in_progress (scheduled job when start_date arrives)
            // confirmed → cancelled (admin action)
            'confirmed' => ['in_progress', 'cancelled'],

            // in_progress → completed (admin action after return)
            // in_progress → cancelled (exceptional: forced offboarding of closing tenant)
            'in_progress' => ['completed', 'cancelled'],

            // completed → refunded (refund request)
            'completed' => ['refunded'],

            // Terminal states: cancelled, refunded (no outgoing transitions)
        ];
    }

    /**
     * Hooks executed after a transition completes.
     *
     * Each key is a $to state; value is an array of callables($from, $model).
     * OrderPaid is dispatched directly from Przelewy24Service (webhook context).
     */
    public function afterTransitionHooks(): array
    {
        return [
            'confirmed' => [
                function (string $from, $model): void {
                    event(new OrderConfirmed($model));
                },
            ],
            'cancelled' => [
                function (string $from, $model): void {
                    event(new OrderCancelled($model));
                },
            ],
        ];
    }
}
