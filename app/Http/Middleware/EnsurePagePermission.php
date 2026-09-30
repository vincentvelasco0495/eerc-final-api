<?php

namespace App\Http\Middleware;

use App\Support\PageAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePagePermission
{
    public function handle(Request $request, Closure $next, string $pagePath): Response
    {
        if ($response = PageAuthorization::denyUnlessCanAccess($request, $pagePath)) {
            return $response;
        }

        return $next($request);
    }
}
