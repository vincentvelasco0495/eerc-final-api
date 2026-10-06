<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankPaymentMethod;
use App\Models\EwalletPaymentMethod;
use App\Support\HttpCache;
use App\Support\LmsCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Public read for enrollment / payment instructions (no auth).
 */
class PaymentMethodController extends Controller
{
    public function index(): JsonResponse|Response
    {
        $payload = LmsCache::remember(LmsCache::PAYMENT_METHODS, 300, function () {
            $banks = BankPaymentMethod::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (BankPaymentMethod $m) => [
                    'id' => $m->public_id,
                    'accountName' => $m->account_name,
                    'bankName' => $m->bank_name,
                    'accountNumber' => $m->account_number,
                ])
                ->values()
                ->all();

            $ewallets = EwalletPaymentMethod::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (EwalletPaymentMethod $m) => [
                    'id' => $m->public_id,
                    'mobileNumber' => $m->mobile_number,
                    'accountName' => $m->account_name,
                ])
                ->values()
                ->all();

            return [
                'data' => [
                    'banks' => $banks,
                    'ewallets' => $ewallets,
                ],
            ];
        });

        return HttpCache::json($payload, 120, true);
    }
}
