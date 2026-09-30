<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankPaymentMethod;
use App\Models\EwalletPaymentMethod;
use Illuminate\Http\JsonResponse;

/**
 * Public read for enrollment / payment instructions (no auth).
 */
class PaymentMethodController extends Controller
{
    public function index(): JsonResponse
    {
        $banks = BankPaymentMethod::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (BankPaymentMethod $m) => [
                'id' => $m->public_id,
                'accountName' => $m->account_name,
                'bankName' => $m->bank_name,
                'accountNumber' => $m->account_number,
            ]);

        $ewallets = EwalletPaymentMethod::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (EwalletPaymentMethod $m) => [
                'id' => $m->public_id,
                'mobileNumber' => $m->mobile_number,
                'accountName' => $m->account_name,
            ]);

        return response()->json([
            'data' => [
                'banks' => $banks,
                'ewallets' => $ewallets,
            ],
        ]);
    }
}
