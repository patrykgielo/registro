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
        try {
            $this->p24->handleWebhook($request->all());
        } catch (\Exception $e) {
            Log::error('P24 webhook error', ['error' => $e->getMessage()]);
        }

        return response('OK', 200);
    }
}
