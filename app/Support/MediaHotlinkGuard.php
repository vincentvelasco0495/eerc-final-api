<?php

namespace App\Support;

use Illuminate\Http\Request;

class MediaHotlinkGuard
{
    /**
     * Block opening a media URL in the address bar / new tab / native Download.
     * HTML5 <video> sends Sec-Fetch-Dest: video. Chrome Download / fetch() send empty.
     */
    public static function denyDirectOpen(Request $request): void
    {
        $mode = strtolower(trim((string) $request->headers->get('Sec-Fetch-Mode', '')));
        $dest = strtolower(trim((string) $request->headers->get('Sec-Fetch-Dest', '')));

        if ($mode === 'navigate' || in_array($dest, ['document', 'iframe', 'embed', 'object', 'frame', 'download'], true)) {
            abort(403, 'This video can only be watched in the lesson player.');
        }

        $accept = strtolower(trim((string) $request->headers->get('Accept', '')));
        if ($accept !== '' && str_starts_with($accept, 'text/html')) {
            abort(403, 'This video can only be watched in the lesson player.');
        }

        if ($request->headers->has('Sec-Fetch-Dest') && ! in_array($dest, ['video', 'audio'], true)) {
            abort(403, 'This video can only be watched in the lesson player.');
        }
    }
}
