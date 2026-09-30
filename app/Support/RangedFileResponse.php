<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RangedFileResponse
{
    /**
     * Stream a file with HTTP Range support so HTML5 video can seek and many
     * concurrent viewers do not each force a full-file PHP download.
     *
     * When `lms.video.accel_redirect` is set (nginx X-Accel-Redirect prefix),
     * PHP only returns headers and nginx serves bytes — required for thousands
     * of simultaneous watchers.
     */
    public static function make(
        string $absolutePath,
        string $mime,
        bool $inline = true,
        ?string $downloadName = null,
        ?string $accelRelativePath = null,
    ): BinaryFileResponse|\Illuminate\Http\Response {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            abort(404, 'File missing on storage.');
        }

        $isVideo = str_starts_with(strtolower($mime), 'video/');
        $cacheControl = $inline && $isVideo ? 'private, no-store' : 'public, max-age=86400';

        $accelPrefix = trim((string) config('lms.video.accel_redirect', ''));
        if ($accelPrefix !== '' && is_string($accelRelativePath) && trim($accelRelativePath) !== '') {
            $redirect = rtrim($accelPrefix, '/').'/'.ltrim(str_replace('\\', '/', $accelRelativePath), '/');

            return response('', 200, [
                'X-Accel-Redirect' => $redirect,
                'Content-Type' => $mime !== '' ? $mime : 'application/octet-stream',
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => $cacheControl,
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
            ]);
        }

        $headers = [
            'Content-Type' => $mime !== '' ? $mime : 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Access-Control-Expose-Headers' => 'Accept-Ranges, Content-Range, Content-Length, Content-Type',
        ];

        $disposition = $inline ? 'inline' : 'attachment';
        $response = new BinaryFileResponse($absolutePath, 200, $headers, true, $disposition);
        if (is_string($downloadName) && trim($downloadName) !== '') {
            $response->setContentDisposition($disposition, $downloadName);
        }
        $response->setAutoLastModified();

        return $response;
    }
}
