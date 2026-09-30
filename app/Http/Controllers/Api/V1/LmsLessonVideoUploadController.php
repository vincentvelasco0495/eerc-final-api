<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\CmsMedia;
use App\Services\LessonVideoUploadService;
use App\Services\LmsCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LmsLessonVideoUploadController extends Controller
{
    use ResolvesLmsActor;

    public function store(Request $request, LessonVideoUploadService $uploads): JsonResponse
    {
        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        $validated = $request->validate([
            'originalName' => ['required', 'string', 'max:255'],
            'sizeBytes' => ['required', 'integer', 'min:1'],
            'mime' => ['sometimes', 'nullable', 'string', 'max:128'],
            'chunkSize' => ['required', 'integer', 'min:1'],
            'totalChunks' => ['required', 'integer', 'min:1'],
            'targetKind' => ['required', 'in:module,standalone,assignment,cms'],
            'targetPublicId' => ['required', 'string', 'max:64'],
            'moduleResourcePublicId' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $status = $uploads->init($actor, [
            'original_name' => $validated['originalName'],
            'size_bytes' => $validated['sizeBytes'],
            'mime' => $validated['mime'] ?? 'video/mp4',
            'chunk_size' => $validated['chunkSize'],
            'total_chunks' => $validated['totalChunks'],
            'target_kind' => $validated['targetKind'],
            'target_public_id' => $validated['targetPublicId'],
            'module_resource_public_id' => $validated['moduleResourcePublicId'] ?? null,
        ]);

        return response()->json(['data' => $status], 201);
    }

    public function show(string $uploadId, LessonVideoUploadService $uploads): JsonResponse
    {
        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        return response()->json(['data' => $uploads->status($actor, $uploadId)]);
    }

    public function storeChunk(
        Request $request,
        string $uploadId,
        int $index,
        LessonVideoUploadService $uploads
    ): JsonResponse {
        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        $request->validate([
            'chunk' => ['required', 'file', 'max:8192'],
        ]);

        $status = $uploads->storeChunk($actor, $uploadId, $index, $request->file('chunk'));

        return response()->json(['data' => $status]);
    }

    public function complete(
        string $uploadId,
        LessonVideoUploadService $uploads,
        LmsCatalogService $catalog
    ): JsonResponse {
        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        $result = $uploads->complete($actor, $uploadId);

        if ($result instanceof CmsMedia) {
            return response()->json([
                'data' => [
                    'id' => $result->public_id,
                    'url' => $result->url,
                    'filename' => $result->filename,
                    'originalName' => $result->original_name,
                    'mime' => $result->mime,
                    'size' => (int) $result->size_bytes,
                    'alt' => $result->alt,
                    'kind' => 'cms',
                ],
            ], 201);
        }

        return response()->json([
            'data' => $catalog->formatLessonMaterial($result, $actor),
        ], 201);
    }

    public function destroy(string $uploadId, LessonVideoUploadService $uploads): JsonResponse
    {
        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        $uploads->abort($actor, $uploadId);

        return response()->json(['ok' => true]);
    }
}
