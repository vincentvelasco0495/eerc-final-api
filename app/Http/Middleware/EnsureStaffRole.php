<?php

namespace App\Http\Middleware;

use App\Support\AdminAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = AdminAuthorization::resolveUser($request);
        $role = strtolower(trim((string) ($user?->role ?? '')));

        if ($user && in_array($role, ['admin', 'instructor'], true)) {
            return $next($request);
        }

        return response()->json(['message' => 'Forbidden.'], 403);
    }
}
