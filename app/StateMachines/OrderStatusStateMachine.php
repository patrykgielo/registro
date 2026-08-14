<?php

declare(strict_types=1);

namespace App\StateMachines;

use App\Events\OrderCancelled;
use App\Events\OrderConfirmed;
use App\Events\OrderHandedOver;
use App\Events\OrderReturned;
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
            // Handover: admin action "Wydano klientowi" (OrderResource row action /
            // EditOrder header action, both call the same transitionTo('in_progress')).
            // No timestamp column for this transition exists (deliberately — see
            // order-notifications.md); the event is the only record.
            'in_progress' => [
                function (string $from, $model): void {
                    event(new OrderHandedOver($model));
                },
            ],
            // Mirrors paid_at/cancelled_at (see Przelewy24Service, OrderService::cancel):
            // completed_at was declared in the schema/fillable/casts but never written
            // anywhere. Lives here rather than at the call site because 'completed' is
            // reached from two independent Filament call sites (OrderResource row action
            // and EditOrder header action) — a hook is the single source of truth instead
            // of duplicating the write in both places.
            //
            // Idempotency: the state machine's own transitionTo() already no-ops when
            // $to === currentState() (see StateMachine::transitionTo()), and the
            // transitions() map only allows 'completed' to be reached from 'in_progress'
            // — there is no path back into 'in_progress' from 'completed', so a genuine
            // re-entry is not reachable today. The null-check is defense-in-depth against
            // that assumption changing later, not a currently-exercised path.
            //
            // Deliberately its OWN callable, separate from the OrderReturned dispatch
            // below — "did we already stamp completed_at" and "should the customer be
            // emailed" are different questions and must not share a guard. A backfill,
            // data migration, import, or seeder can set completed_at directly (bypassing
            // this hook entirely, since none of those call transitionTo()) without that
            // ever meaning a customer was emailed about a return. If a future path DID
            // call transitionTo('completed') on an order whose completed_at was already
            // set by one of those, coupling the two would silently skip the email even
            // though a genuine, hook-firing transition just happened. The email hook
            // below has no such guard: this whole array is Laravel's own
            // afterTransitionHooks() mechanism, which only invokes 'completed' callables
            // on a genuine transition INTO 'completed' (see the idempotency note above)
            // — so it needs no guard of its own to avoid a double-send, exactly like the
            // 'confirmed'/'in_progress'/'cancelled' hooks above, none of which guard either.
            'completed' => [
                function (string $from, $model): void {
                    if ($model->completed_at === null) {
                        $model->update(['completed_at' => now()]);
                    }
                },
                function (string $from, $model): void {
                    event(new OrderReturned($model));
                },
            ],
        ];
    }
}
