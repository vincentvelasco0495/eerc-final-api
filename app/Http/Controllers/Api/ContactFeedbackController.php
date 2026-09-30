<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactFeedbackSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContactFeedbackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'email' => 'required|email|max:190',
            'phone' => 'nullable|string|max:40',
            'message' => 'required|string|max:20000',
        ]);

        $row = ContactFeedbackSubmission::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'message' => $validated['message'],
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) ($request->userAgent() ?? ''), 1024),
        ]);

        return response()->json([
            'data' => [
                'id' => $row->id,
                'createdAt' => $row->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}
