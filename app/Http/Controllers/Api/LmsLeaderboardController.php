<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LmsCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LmsLeaderboardController extends Controller
{
    public function show(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $type = (string) $request->query('type', 'daily');
        $rows = $catalog->leaderboardForPeriod($type);

        return response()->json(['data' => $rows])
            ->header('Cache-Control', 'public, max-age=30, stale-while-revalidate=120');
    }
}
