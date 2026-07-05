<?php

declare(strict_types=1);

namespace App\StateMachines;

use App\Events\OrderCancelled;
use App\Events\OrderConfirmed;
use Asantibanez\LaravelEloquentStateMachines\StateMachines\StateMachine;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFacade;

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

            // completed => refunded (refund request)
            'completed' => ['refunded'],

            // cancelled => paid (RECONCILIATION ONLY -- see Przelewy24Service::handleWebhook())
            // A genuine P24 success webhook can arrive after orders:cleanup-expired already
            // cancelled the order (e.g. a slow bank/BLIK confirmation racing the TTL cron).
            // Money was actually captured, so the order must be recoverable back to 'paid'
            // rather than permanently orphaned.
            //
            // This is NOT enforced by convention alone: validatorForTransition() below
            // requires a Payment(status=success) row to exist before this specific
            // transition is allowed, so ANY caller (admin action, support script, future
            // bug) that tries to force cancelled -> paid without a verified payment behind
            // it is blocked with a ValidationException, regardless of who calls it.
            'cancelled' => ['paid'],

            // Terminal state: refunded (no outgoing transitions)
        ];
    }

    /**
     * Self-defending guard for the cancelled -> paid reconciliation transition:
     * regardless of the caller, this transition is only allowed when a
     * successful Payment record already exists for the order. This is what
     * actually enforces "reconciliation only" — the transitions() map alone
     * only says the transition is legal, not that it's safe.
     */
    public function validatorForTransition($from, $to, $model): ?Validator
    {
        if ($from === 'cancelled' && $to === 'paid') {
            $hasVerifiedPayment = $model->payments()->where('status', 'success')->exists();

            return ValidatorFacade::make(
                ['has_verified_payment' => $hasVerifiedPayment],
                ['has_verified_payment' => 'accepted'],
                [
                    'has_verified_payment.accepted' => "Cannot reconcile order #{$model->id} from 'cancelled' to 'paid' without an existing successful Payment record.",
                ]
            );
        }

        return null;
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
                    event(new OrderCancelled($model, notify: $model->notifyOnCancel ?? true));
                },
            ],
        ];
    }
}
