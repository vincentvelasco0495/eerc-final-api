<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Services\LmsCatalogService;
use Illuminate\Http\JsonResponse;

class LmsAnalyticsController extends Controller
{
    use ResolvesLmsActor;

    public function show(LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor();
        $payload = $catalog->analyticsForUser($user);

        return response()->json($payload)
            ->header('Cache-Control', 'public, max-age=30, stale-while-revalidate=120');
    }
}
