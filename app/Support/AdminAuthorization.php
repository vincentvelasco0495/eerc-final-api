<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AdminAuthorization
{
    /** Resolve the authenticated user, including Bearer tokens on public routes. */
    public static function resolveUser(Request $request): ?User
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user;
        }

        $plainTextToken = $request->bearerToken();

        if (! $plainTextToken) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($plainTextToken);
        $tokenable = $accessToken?->tokenable;

        return $tokenable instanceof User ? $tokenable : null;
    }

}
