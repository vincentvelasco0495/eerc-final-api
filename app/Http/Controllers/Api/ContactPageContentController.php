<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContactPageContentService;
use App\Support\HttpCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ContactPageContentController extends Controller
{
    public function show(Request $request, ContactPageContentService $service): JsonResponse|Response
    {
        $preview = filter_var($request->query('preview'), FILTER_VALIDATE_BOOLEAN);
        $user = $request->user();

        if ($user === null && $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken($request->bearerToken());
            $user = $accessToken?->tokenable;
        }

        $includeDraft = $preview && $user !== null;
        $payload = $service->publicPayload($includeDraft);

        return HttpCache::json($payload, $includeDraft ? 0 : 120, ! $includeDraft);
    }
}
