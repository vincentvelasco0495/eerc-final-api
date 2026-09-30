<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LmsCatalogService;
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

    protected function canManageEnrollments(User $user): bool
    {
        if ($user->id <= 0) {
            return false;
        }

        $role = strtolower(trim((string) ($user->role ?? '')));

        return $role === 'instructor' || $role === 'admin';
    }
}
