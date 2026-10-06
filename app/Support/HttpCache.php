<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HttpCache
{
    public static function json(array $data, int $maxAge = 60, bool $isPublic = true): JsonResponse|Response
    {
        if ($maxAge < 1) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        $etag = '"'.sha1((string) json_encode($data)).'"';
        $cacheControl = $isPublic
            ? 'public, max-age='.$maxAge.', s-maxage='.$maxAge
            : 'private, max-age='.$maxAge;
        $request = request();
        if ($request instanceof Request && self::etagMatches($request, $etag)) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => $cacheControl,
                'Vary' => 'Authorization, Accept',
            ]);
        }

        return response()->json($data)
            ->header('ETag', $etag)
            ->header('Cache-Control', $cacheControl)
            ->header('Vary', 'Authorization, Accept');
    }

    protected static function etagMatches(Request $request, string $etag): bool
    {
        $incoming = trim((string) $request->headers->get('If-None-Match'));
        if ($incoming === '') {
            return false;
        }
        $normalize = static fn (string $value): string => trim($value, " \t\"'");
        $expected = $normalize($etag);
        foreach (explode(',', $incoming) as $part) {
            if ($normalize($part) === $expected) {
                return true;
            }
        }

        return false;
    }
}
