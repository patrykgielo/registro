<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Payment\Przelewy24Service;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(protected Przelewy24Service $p24) {}

    public function przelewy24(Request $request): Response
    {
        // \Throwable for the same reason as CheckoutController::submit(): an
        // \Error out of the payment SDK (e.g. an unconfigured gateway) is not
        // an \Exception, and letting one escape here turns a webhook delivery
        // into a 500 that P24 will keep redelivering.
        try {
            $this->p24->handleWebhook($request->all());
        } catch (\Throwable $e) {
            Log::error('P24 webhook error', ['error' => $e->getMessage()]);
        }

        return response('OK', 200);
    }
}
