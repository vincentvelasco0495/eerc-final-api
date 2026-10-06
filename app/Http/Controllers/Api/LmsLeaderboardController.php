<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LmsCatalogService;
use App\Support\ExcelDownload;
use App\Support\ExportDateRange;
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

    public function export(Request $request, LmsCatalogService $catalog)
    {
        $validated = $request->validate(array_merge(ExportDateRange::rules(), [
            'type' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]));
        [$from, $to] = ExportDateRange::extract($validated);
        $type = isset($validated['type']) && trim((string) $validated['type']) !== ''
            ? (string) $validated['type']
            : 'daily';

        return ExcelDownload::make(
            'Leaderboard',
            ['Rank', 'Learner', 'Program', 'Score', 'Badge'],
            $catalog->leaderboardForExport($type, $from, $to),
            'leaderboard-'.$type
        );
    }
}
