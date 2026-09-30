<?php

namespace App\Http\Middleware;

use App\Support\AdminAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = AdminAuthorization::resolveUser($request);

        if ($user && strtolower(trim((string) $user->role)) === 'admin') {
            return $next($request);
        }

        return response()->json(['message' => 'Forbidden.'], 403);
    }
}
