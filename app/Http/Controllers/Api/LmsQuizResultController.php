<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Services\LmsCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LmsQuizResultController extends Controller
{
    use ResolvesLmsActor;

    public function index(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();
        $userId = $request->query('userId');

        if ($userId !== null && $userId !== '' && (string) $userId !== $actor->public_uid) {
            abort(403, 'Cannot access quiz results for another user.');
        }

        return response()->json(['data' => $catalog->quizResultsForUser($actor)]);
    }
}
