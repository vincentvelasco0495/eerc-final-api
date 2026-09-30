<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AboutPageContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AboutPageContentAdminController extends Controller
{
    public function show(AboutPageContentService $service): JsonResponse
    {
        return response()->json($service->adminPayload());
    }

    public function update(Request $request, string $section, AboutPageContentService $service): JsonResponse
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

    public function publishAll(AboutPageContentService $service): JsonResponse
    {
        $service->publishAll();

        return response()->json($service->adminPayload());
    }
}
