<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\CmsMedia;
use App\Support\RangedFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CmsMediaUploadController extends Controller
{
    use ResolvesLmsActor;

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,mp4,webm,ogg|max:51200',
            'alt' => 'nullable|string|max:255',
        ]);

        $file = $validated['file'];
        $user = $this->lmsActor();

        $stored = Storage::disk('public')->putFile('cms/homepage-v2', $file);
        // Persist relative URL so frontend can resolve against current API host.
        $url = '/storage/'.ltrim($stored, '/');

        $media = CmsMedia::query()->create([
            'public_id' => 'media-'.Str::lower(Str::ulid()),
            'uploaded_by' => $user->id,
            'disk' => 'public',
            'path' => $stored,
            'url' => $url,
            'filename' => basename($stored),
            'original_name' => $file->getClientOriginalName() ?: basename($stored),
            'mime' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'alt' => $validated['alt'] ?? null,
        ]);

        return response()->json(['data' => $this->formatMedia($media)], 201);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $media = CmsMedia::query()->where('public_id', $publicId)->first();

        if ($media === null) {
            return response()->json(['message' => 'Media not found.'], 404);
        }

        if ($media->disk && $media->path) {
            try {
                if (Storage::disk($media->disk)->exists($media->path)) {
                    Storage::disk($media->disk)->delete($media->path);
                }
            } catch (\Throwable) {
                /* disk may be missing on older rows */
            }
        }

        $filename = basename((string) ($media->filename ?: $media->path));
        if ($filename !== '') {
            $webPath = public_path('cms-videos/'.$filename);
            if (is_file($webPath)) {
                @unlink($webPath);
            }
        }

        $media->delete();

        return response()->json(['ok' => true]);
    }

    public function file(string $publicId)
    {
        set_time_limit(0);

        $media = CmsMedia::query()->where('public_id', $publicId)->first();

        if ($media === null || ! $media->path) {
            abort(404, 'Media not found.');
        }

        $filename = basename((string) ($media->filename ?: $media->path));
        $candidates = [
            public_path('cms-videos/'.$filename),
            public_path('storage/cms/homepage-v2/'.$filename),
            storage_path('app/public/cms/homepage-v2/'.$filename),
            storage_path('app/private/cms/homepage-v2/'.$filename),
        ];
        if ($media->disk) {
            try {
                $disk = Storage::disk($media->disk);
                if ($disk->exists($media->path)) {
                    array_unshift($candidates, $disk->path($media->path));
                }
            } catch (\Throwable) {
                /* unknown disk name on older rows */
            }
        }

        foreach ($candidates as $absolutePath) {
            if (is_file($absolutePath) && is_readable($absolutePath)) {
                return RangedFileResponse::make(
                    $absolutePath,
                    $media->mime ?: 'video/mp4',
                    true,
                    $media->original_name ?: $filename
                );
            }
        }

        abort(404, 'Media file not found.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatMedia(CmsMedia $media): array
    {
        return [
            'id' => $media->public_id,
            'url' => $media->url,
            'filename' => $media->filename,
            'originalName' => $media->original_name,
            'mime' => $media->mime,
            'size' => (int) $media->size_bytes,
            'alt' => $media->alt,
        ];
    }
}
