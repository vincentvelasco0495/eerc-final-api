<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\HomepageContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomepageContentAdminController extends Controller
{
    public function show(HomepageContentService $service): JsonResponse
    {
        return response()->json($service->adminPayload());
    }

    public function update(Request $request, string $section, HomepageContentService $service): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|array',
            'status' => 'sometimes|in:draft,published',
        ]);

        $row = $service->updateSection(
            $section,
            $validated['content'],
            $validated['status'] ?? null
        );

        return response()->json(['data' => $row]);
    }

    public function publishAll(HomepageContentService $service): JsonResponse
    {
        $service->publishAll();

        return response()->json($service->adminPayload());
    }
}
