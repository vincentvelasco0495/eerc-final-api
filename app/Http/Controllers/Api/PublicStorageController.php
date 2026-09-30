<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\RangedFileResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PublicStorageController extends Controller
{
    public function show(Request $request)
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
        ]);

        $path = $this->normalizeIncomingPath((string) $validated['path']);
        if ($path === '' || str_contains($path, '..')) {
            abort(404, 'File not found.');
        }

        $public = Storage::disk('public');
        $local = Storage::disk('local');
        foreach ($this->candidatePaths($path) as $candidate) {
            if ($candidate === '' || str_contains($candidate, '..')) {
                continue;
            }
            if ($public->exists($candidate)) {
                $mime = (string) (@mime_content_type($public->path($candidate)) ?: 'application/octet-stream');
                if ($this->isProtectedLessonVideo($candidate, $mime)) {
                    abort(403, 'This video can only be watched in the lesson player.');
                }

                return RangedFileResponse::make(
                    $public->path($candidate),
                    $mime,
                    true,
                    basename($candidate),
                    $candidate
                );
            }
            // Backward compatibility: older uploads may still be on the local disk.
            if ($local->exists($candidate)) {
                $mime = (string) (@mime_content_type($local->path($candidate)) ?: 'application/octet-stream');
                if ($this->isProtectedLessonVideo($candidate, $mime)) {
                    abort(403, 'This video can only be watched in the lesson player.');
                }

                return RangedFileResponse::make(
                    $local->path($candidate),
                    $mime,
                    true,
                    basename($candidate)
                );
            }
        }

        abort(404, 'File not found.');
    }

    private function isProtectedLessonVideo(string $path, string $mime): bool
    {
        if (str_starts_with(strtolower($mime), 'video/')) {
            return true;
        }

        return str_contains(str_replace('\\', '/', $path), 'lesson-materials/videos/');
    }

    private function normalizeIncomingPath(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }

        $decoded = rawurldecode($s);
        if ($decoded !== '') {
            $s = $decoded;
        }

        if (preg_match('#^https?://#i', $s)) {
            $parsedPath = parse_url($s, PHP_URL_PATH);
            if (is_string($parsedPath) && trim($parsedPath) !== '') {
                $s = trim($parsedPath);
            }
        }

        $s = str_replace('\\', '/', $s);
        $s = ltrim($s, '/');
        if (str_starts_with($s, 'storage/')) {
            $s = substr($s, 8);
        }

        return ltrim($s, '/');
    }

    /** @return array<int, string> */
    private function candidatePaths(string $normalized): array
    {
        $candidates = [$normalized];

        if (str_starts_with($normalized, 'public/')) {
            $candidates[] = ltrim(substr($normalized, 7), '/');
        }
        if (str_starts_with($normalized, 'app/public/')) {
            $candidates[] = ltrim(substr($normalized, 11), '/');
        }
        if (str_starts_with($normalized, 'app/private/')) {
            $candidates[] = ltrim(substr($normalized, 12), '/');
        }

        return array_values(array_unique(array_filter($candidates, fn ($v) => is_string($v) && trim($v) !== '')));
    }
}
