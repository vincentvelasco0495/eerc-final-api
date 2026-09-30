<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\LmsCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LmsGradebookController extends Controller
{
    use ResolvesLmsActor;

    public function courses(LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();

        if (! $catalog->userCanViewGradebook($actor)) {
            abort(403, 'You do not have permission to view the gradebook.');
        }

        return response()->json([
            'data' => $catalog->gradebookCoursesForStaff(),
        ]);
    }

    public function show(Request $request, string $coursePublicId, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();

        if (! $catalog->userCanViewGradebook($actor)) {
            abort(403, 'You do not have permission to view the gradebook.');
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,30,50,100'],
        ]);

        $course = Course::query()
            ->where('public_id', $coursePublicId)
            ->firstOrFail();

        return response()->json(
            $catalog->gradebookPaginatedForCourse(
                $course,
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 10)
            )
        );
    }
}
