<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LmsCatalogService;
use App\Support\ExcelDownload;
use App\Support\ExportDateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LmsAdminSummaryController extends Controller
{
    public function show(LmsCatalogService $catalog): JsonResponse
    {
        return response()->json($catalog->adminPayload());
    }

    public function exportUsers(Request $request, LmsCatalogService $catalog)
    {
        $validated = $request->validate(ExportDateRange::rules());
        [$from, $to] = ExportDateRange::extract($validated);

        return ExcelDownload::make(
            'Users',
            ['User', 'Role', 'Program', 'Status'],
            $catalog->adminUsersForExport($from, $to),
            'admin-users'
        );
    }

    public function exportEnrollments(Request $request, LmsCatalogService $catalog)
    {
        $validated = $request->validate(ExportDateRange::rules());
        [$from, $to] = ExportDateRange::extract($validated);

        return ExcelDownload::make(
            'Enrollments',
            ['Program', 'Submitted', 'Status'],
            $catalog->adminEnrollmentsForExport($from, $to),
            'admin-enrollments'
        );
    }
}
