<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HomepageContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class HomepageContentController extends Controller
{
    public function show(Request $request, HomepageContentService $service): JsonResponse
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
