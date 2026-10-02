<?php

namespace App\Http\Controllers;

use App\Support\RangedFileResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CmsVideoFileController extends Controller
{
    public function show(Request $request, string $filename): BinaryFileResponse|\Illuminate\Http\Response
    {
        set_time_limit(0);

        if (! preg_match('/^[A-Za-z0-9._-]+\.(mp4|webm|ogg)$/i', $filename)) {
            abort(404, 'Video file not found.');
        }

        $stem = pathinfo($filename, PATHINFO_FILENAME);

        $candidates = [
            public_path('cms-videos/'.$filename),
            public_path('storage/cms/homepage-v2/'.$filename),
            storage_path('app/public/cms/homepage-v2/'.$filename),
            storage_path('app/private/cms/homepage-v2/'.$filename),
            storage_path('app/private/video-uploads/'.$stem.'/assembled.partial'),
        ];

        foreach ($candidates as $absolutePath) {
            if (is_file($absolutePath) && is_readable($absolutePath)) {
                $mime = (string) (@mime_content_type($absolutePath) ?: '');
                if (! str_starts_with(strtolower($mime), 'video/')) {
                    $mime = 'video/mp4';
                }

                return RangedFileResponse::make($absolutePath, $mime, true, $filename, null, true);
            }
        }

        abort(404, 'Video file not found.');
    }
}
