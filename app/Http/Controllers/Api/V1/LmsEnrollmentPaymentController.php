<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LmsCatalogService;
use App\Support\ExcelDownload;
use App\Support\ExportDateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LmsEnrollmentPaymentController extends Controller
{
    use ResolvesLmsActor;

    public function index(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        if (! $this->canManageEnrollments($actor)) {
            abort(403, 'You cannot view enrollment payment history.');
        }

        $validated = $request->validate([
            'page' => ['required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'verification' => ['sometimes', 'nullable', 'string', 'in:pending,correct,invalid'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) $validated['page'];
        $search = isset($validated['search']) ? (string) $validated['search'] : null;
        $verification = isset($validated['verification']) ? (string) $validated['verification'] : null;

        return response()->json(
            $catalog->enrollmentPaymentsPaginated($page, $perPage, $search, $verification)
        );
    }

    public function export(Request $request, LmsCatalogService $catalog)
    {
        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        if (! $this->canManageEnrollments($actor)) {
            abort(403, 'You cannot export enrollment payment history.');
        }

        $validated = $request->validate(array_merge(ExportDateRange::rules(), [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'verification' => ['sometimes', 'nullable', 'string', 'in:pending,correct,invalid'],
        ]));
        [$from, $to] = ExportDateRange::extract($validated);

        return ExcelDownload::make(
            'Payments',
            ['Learner', 'Email', 'Program', 'Payment', 'Amount', 'Paid on', 'Status'],
            $catalog->enrollmentPaymentsForExport(
                isset($validated['search']) ? (string) $validated['search'] : null,
                isset($validated['verification']) ? (string) $validated['verification'] : null,
                $from,
                $to
            ),
            'payment-history'
        );
    }

    protected function canManageEnrollments(User $user): bool
    {
        if ($user->id <= 0) {
            return false;
        }

        $role = strtolower(trim((string) ($user->role ?? '')));

        return $role === 'instructor' || $role === 'admin';
    }
}
