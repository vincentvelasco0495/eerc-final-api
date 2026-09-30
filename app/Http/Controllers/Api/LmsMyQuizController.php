<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Services\LmsCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LmsMyQuizController extends Controller
{
    use ResolvesLmsActor;

    public function index(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor();

        if ($user->id <= 0) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 10,
                    'total' => 0,
                    'from' => 0,
                    'to' => 0,
                ],
            ]);
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,30,50,100'],
            'status' => ['sometimes', 'nullable', 'string', 'in:all,passed,failed,pending'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return response()->json(
            $catalog->myQuizzesPaginatedForUser(
                $user,
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 10),
                isset($validated['status']) ? (string) $validated['status'] : 'all',
                isset($validated['search']) ? (string) $validated['search'] : null
            )
        );
    }
}
