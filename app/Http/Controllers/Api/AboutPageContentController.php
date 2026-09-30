<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AboutPageContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AboutPageContentController extends Controller
{
    public function show(Request $request, AboutPageContentService $service): JsonResponse
    {
        $preview = filter_var($request->query('preview'), FILTER_VALIDATE_BOOLEAN);
        $user = $request->user();

        if ($user === null && $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken($request->bearerToken());
            $user = $accessToken?->tokenable;
        }

        $includeDraft = $preview && $user !== null;

        return response()->json($service->publicPayload($includeDraft));
    }
}
