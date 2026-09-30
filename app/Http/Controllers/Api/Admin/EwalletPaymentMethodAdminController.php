<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EwalletPaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EwalletPaymentMethodAdminController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = EwalletPaymentMethod::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (EwalletPaymentMethod $m) => $this->format($m));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobileNumber' => ['required', 'string', 'max:64'],
            'accountName' => ['required', 'string', 'max:255'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        $maxSort = (int) EwalletPaymentMethod::query()->max('sort_order');
        $row = EwalletPaymentMethod::query()->create([
            'public_id' => (string) Str::uuid(),
            'mobile_number' => trim($validated['mobileNumber']),
            'account_name' => trim($validated['accountName']),
            'sort_order' => isset($validated['sortOrder']) ? (int) $validated['sortOrder'] : $maxSort + 1,
        ]);

        return response()->json(['data' => $this->format($row->fresh())], 201);
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $row = EwalletPaymentMethod::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'mobileNumber' => ['sometimes', 'string', 'max:64'],
            'accountName' => ['sometimes', 'string', 'max:255'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        if (array_key_exists('mobileNumber', $validated)) {
            $row->mobile_number = trim((string) $validated['mobileNumber']);
        }
        if (array_key_exists('accountName', $validated)) {
            $row->account_name = trim((string) $validated['accountName']);
        }
        if (array_key_exists('sortOrder', $validated)) {
            $row->sort_order = (int) $validated['sortOrder'];
        }

        $row->save();

        return response()->json(['data' => $this->format($row->fresh())]);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $row = EwalletPaymentMethod::query()->where('public_id', $publicId)->firstOrFail();
        $row->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(EwalletPaymentMethod $m): array
    {
        return [
            'id' => $m->public_id,
            'mobileNumber' => $m->mobile_number,
            'accountName' => $m->account_name,
            'sortOrder' => (int) $m->sort_order,
            'updatedAt' => $m->updated_at?->toIso8601String(),
        ];
    }
}
