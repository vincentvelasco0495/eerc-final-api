<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ContactPageContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactPageContentAdminController extends Controller
{
    public function show(ContactPageContentService $service): JsonResponse
    {
        return response()->json($service->adminPayload());
    }

    public function update(Request $request, string $section, ContactPageContentService $service): JsonResponse
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

    public function publishAll(ContactPageContentService $service): JsonResponse
    {
        $service->publishAll();

        return response()->json($service->adminPayload());
    }
}
