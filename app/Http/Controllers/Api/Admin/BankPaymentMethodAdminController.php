<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankPaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BankPaymentMethodAdminController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = BankPaymentMethod::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (BankPaymentMethod $m) => $this->format($m));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'accountName' => ['required', 'string', 'max:255'],
            'bankName' => ['required', 'string', 'max:255'],
            'accountNumber' => ['required', 'string', 'max:191'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        $maxSort = (int) BankPaymentMethod::query()->max('sort_order');
        $row = BankPaymentMethod::query()->create([
            'public_id' => (string) Str::uuid(),
            'account_name' => trim($validated['accountName']),
            'bank_name' => trim($validated['bankName']),
            'account_number' => trim($validated['accountNumber']),
            'sort_order' => isset($validated['sortOrder']) ? (int) $validated['sortOrder'] : $maxSort + 1,
        ]);

        return response()->json(['data' => $this->format($row->fresh())], 201);
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $row = BankPaymentMethod::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'accountName' => ['sometimes', 'string', 'max:255'],
            'bankName' => ['sometimes', 'string', 'max:255'],
            'accountNumber' => ['sometimes', 'string', 'max:191'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        if (array_key_exists('accountName', $validated)) {
            $row->account_name = trim((string) $validated['accountName']);
        }
        if (array_key_exists('bankName', $validated)) {
            $row->bank_name = trim((string) $validated['bankName']);
        }
        if (array_key_exists('accountNumber', $validated)) {
            $row->account_number = trim((string) $validated['accountNumber']);
        }
        if (array_key_exists('sortOrder', $validated)) {
            $row->sort_order = (int) $validated['sortOrder'];
        }

        $row->save();

        return response()->json(['data' => $this->format($row->fresh())]);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $row = BankPaymentMethod::query()->where('public_id', $publicId)->firstOrFail();
        $row->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(BankPaymentMethod $m): array
    {
        return [
            'id' => $m->public_id,
            'accountName' => $m->account_name,
            'bankName' => $m->bank_name,
            'accountNumber' => $m->account_number,
            'sortOrder' => (int) $m->sort_order,
            'updatedAt' => $m->updated_at?->toIso8601String(),
        ];
    }
}
