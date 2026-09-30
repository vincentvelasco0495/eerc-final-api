<?php

namespace App\Support;

use App\Services\PagePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageAuthorization
{
    /**
     * @param  array<string, mixed>  $query
     */
    public static function denyUnlessCanAccess(Request $request, string $path, array $query = []): ?JsonResponse
    {
        $user = AdminAuthorization::resolveUser($request);

        if (
            $user
            && app(PagePermissionService::class)->canAccess((string) $user->role, $path, $query)
        ) {
            return null;
        }

        return response()->json(['message' => 'Forbidden.'], 403);
    }
}
