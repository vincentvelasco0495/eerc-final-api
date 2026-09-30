<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\CmsMedia;
use App\Models\LessonMaterial;
use App\Models\Module;
use App\Models\ModuleResource;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LessonVideoUploadService
{
    public function init(User $user, array $payload): array
    {
        $this->pruneStale();

        $sizeBytes = (int) $payload['size_bytes'];
        $chunkSize = (int) $payload['chunk_size'];
        $totalChunks = (int) $payload['total_chunks'];
        $maxBytes = (int) config('lms.video.max_bytes', 8 * 1024 * 1024 * 1024);
        $expectedChunks = (int) ceil($sizeBytes / max(1, $chunkSize));

        if ($sizeBytes < 1 || $sizeBytes > $maxBytes) {
            abort(422, 'Video is empty or larger than the allowed upload size.');
        }
        if ($chunkSize < 256 * 1024 || $chunkSize > 8 * 1024 * 1024) {
            abort(422, 'Invalid chunk size.');
        }
        if ($totalChunks < 1 || $totalChunks !== $expectedChunks || $totalChunks > 16384) {
            abort(422, 'Invalid chunk count for this file.');
        }

        $this->assertTarget(
            (string) $payload['target_kind'],
            (string) $payload['target_public_id'],
            $payload['module_resource_public_id'] ?? null
        );

        $id = (string) Str::uuid();
        $meta = [
            'id' => $id,
            'user_id' => (int) $user->id,
            'original_name' => (string) $payload['original_name'],
            'mime' => (string) ($payload['mime'] ?? 'video/mp4'),
            'size_bytes' => $sizeBytes,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'received' => [],
            'target_kind' => (string) $payload['target_kind'],
            'target_public_id' => (string) $payload['target_public_id'],
            'module_resource_public_id' => $payload['module_resource_public_id'] ?? null,
            'created_at' => now()->toIso8601String(),
        ];

        Storage::disk('local')->makeDirectory($this->partsDir($id));
        $this->writeMeta($id, $meta);

        return $this->statusPayload($meta);
    }

    public function status(User $user, string $uploadId): array
    {
        return $this->statusPayload($this->loadOwnedMeta($user, $uploadId));
    }

    public function storeChunk(User $user, string $uploadId, int $index, UploadedFile $chunk): array
    {
        $meta = $this->loadOwnedMeta($user, $uploadId);
        $total = (int) $meta['total_chunks'];
        $chunkSize = (int) $meta['chunk_size'];
        $sizeBytes = (int) $meta['size_bytes'];

        if ($index < 0 || $index >= $total) {
            abort(422, 'Chunk index is out of range.');
        }

        $expected = $index === $total - 1
            ? ($sizeBytes - ($chunkSize * ($total - 1)))
            : $chunkSize;
        $actual = (int) $chunk->getSize();
        if ($actual < 1 || $actual !== (int) $expected) {
            abort(422, 'Chunk size does not match the upload session.');
        }

        $partRel = $this->partPath($uploadId, $index);
        Storage::disk('local')->putFileAs($this->partsDir($uploadId), $chunk, (string) $index);

        if (! Storage::disk('local')->exists($partRel)) {
            abort(500, 'Could not store video chunk.');
        }

        $meta = $this->mutateMeta($uploadId, function (array $current) use ($index) {
            $received = array_values(array_unique(array_map('intval', $current['received'] ?? [])));
            if (! in_array($index, $received, true)) {
                $received[] = $index;
                sort($received, SORT_NUMERIC);
            }
            $current['received'] = $received;

            return $current;
        });

        return $this->statusPayload($meta);
    }

    public function complete(User $user, string $uploadId): LessonMaterial|CmsMedia
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $meta = $this->loadOwnedMeta($user, $uploadId);
        $total = (int) $meta['total_chunks'];
        $received = array_map('intval', $meta['received'] ?? []);
        $missing = [];
        for ($i = 0; $i < $total; $i++) {
            if (! in_array($i, $received, true) || ! Storage::disk('local')->exists($this->partPath($uploadId, $i))) {
                $missing[] = $i;
            }
        }
        if ($missing !== []) {
            abort(409, 'Upload is incomplete. Missing chunks: '.implode(',', array_slice($missing, 0, 24)));
        }

        $target = $this->assertTarget(
            (string) $meta['target_kind'],
            (string) $meta['target_public_id'],
            $meta['module_resource_public_id'] ?? null
        );

        $ext = strtolower(pathinfo((string) $meta['original_name'], PATHINFO_EXTENSION));
        if ($ext === '' || strlen($ext) > 8) {
            $ext = 'mp4';
        }

        if (($target['kind'] ?? '') === 'cms') {
            return $this->completeAsCmsMedia($user, $uploadId, $meta, $total, $ext);
        }

        $destRel = 'lesson-materials/videos/'.$uploadId.'.'.$ext;
        Storage::disk('local')->makeDirectory('lesson-materials/videos');
        $destAbs = Storage::disk('local')->path($destRel);
        $this->assembleChunks($uploadId, $total, $destAbs, (int) $meta['size_bytes']);

        $mime = $this->detectVideoMime($destAbs, (string) $meta['mime']);

        $material = LessonMaterial::query()->create([
            'public_id' => (string) Str::uuid(),
            'module_id' => $target['module_id'],
            'module_resource_id' => $target['module_resource_id'],
            'assignment_id' => $target['assignment_id'],
            'original_name' => (string) $meta['original_name'],
            'storage_path' => $destRel,
            'mime' => $mime,
            'size_bytes' => (int) $meta['size_bytes'],
        ]);

        $this->deleteSession($uploadId);

        LmsCatalogService::bustUserAnalyticsCache($user->id);

        return $material->fresh();
    }

    public function abort(User $user, string $uploadId): void
    {
        $this->loadOwnedMeta($user, $uploadId);
        $this->deleteSession($uploadId);
    }

    protected function completeAsCmsMedia(User $user, string $uploadId, array $meta, int $total, string $ext): CmsMedia
    {
        $filename = $uploadId.'.'.$ext;
        $destRel = 'cms/homepage-v2/'.$filename;
        Storage::disk('public')->makeDirectory('cms/homepage-v2');
        $destAbs = Storage::disk('public')->path($destRel);
        $this->assembleChunks($uploadId, $total, $destAbs, (int) $meta['size_bytes']);

        $mime = $this->detectVideoMime($destAbs, (string) $meta['mime']);
        $url = '/storage/'.ltrim($destRel, '/');

        $media = CmsMedia::query()->create([
            'public_id' => 'media-'.Str::lower((string) Str::ulid()),
            'uploaded_by' => $user->id > 0 ? $user->id : null,
            'disk' => 'public',
            'path' => $destRel,
            'url' => $url,
            'filename' => $filename,
            'original_name' => (string) $meta['original_name'],
            'mime' => $mime,
            'size_bytes' => (int) $meta['size_bytes'],
            'alt' => null,
        ]);

        $this->deleteSession($uploadId);

        return $media->fresh();
    }

    protected function assembleChunks(string $uploadId, int $total, string $destAbs, int $expectedSize): void
    {
        $dir = dirname($destAbs);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            abort(500, 'Could not create video storage directory.');
        }

        $out = fopen($destAbs, 'wb');
        if ($out === false) {
            abort(500, 'Could not assemble the uploaded video.');
        }

        try {
            for ($i = 0; $i < $total; $i++) {
                $in = fopen(Storage::disk('local')->path($this->partPath($uploadId, $i)), 'rb');
                if ($in === false) {
                    abort(500, 'A stored chunk could not be read.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        $assembledSize = is_file($destAbs) ? (int) filesize($destAbs) : 0;
        if ($assembledSize !== $expectedSize) {
            @unlink($destAbs);
            abort(500, 'Assembled video size does not match the original file.');
        }
    }

    /**
     * @return array{kind?: string, module_id: ?int, module_resource_id: ?int, assignment_id: ?int}
     */
    public function assertTarget(string $kind, string $publicId, mixed $moduleResourcePublicId): array
    {
        $id = trim($publicId);
        if ($id === '') {
            abort(422, 'Upload target is required.');
        }

        if ($kind === 'cms') {
            return [
                'kind' => 'cms',
                'module_id' => null,
                'module_resource_id' => null,
                'assignment_id' => null,
            ];
        }

        if ($kind === 'assignment') {
            $assignment = Assignment::query()->where('public_id', $id)->firstOrFail();

            return [
                'module_id' => null,
                'module_resource_id' => null,
                'assignment_id' => $assignment->id,
            ];
        }

        if ($kind === 'standalone') {
            $resource = ModuleResource::query()
                ->where('public_id', $id)
                ->where('is_standalone_lesson', true)
                ->firstOrFail();

            return [
                'module_id' => null,
                'module_resource_id' => $resource->id,
                'assignment_id' => null,
            ];
        }

        if ($kind !== 'module') {
            abort(422, 'Invalid upload target.');
        }

        $module = Module::query()->where('public_id', $id)->firstOrFail();
        $resourceId = null;
        $resourcePublicId = is_string($moduleResourcePublicId) ? trim($moduleResourcePublicId) : '';
        if ($resourcePublicId !== '') {
            $resource = ModuleResource::query()
                ->where('public_id', $resourcePublicId)
                ->where('module_id', $module->id)
                ->first();
            if ($resource !== null && $resource->is_standalone_lesson) {
                return [
                    'module_id' => null,
                    'module_resource_id' => $resource->id,
                    'assignment_id' => null,
                ];
            }
            if ($resource !== null) {
                $resourceId = $resource->id;
            }
        }

        return [
            'module_id' => $module->id,
            'module_resource_id' => $resourceId,
            'assignment_id' => null,
        ];
    }

    protected function pruneStale(): void
    {
        $root = 'video-uploads';
        if (! Storage::disk('local')->exists($root)) {
            return;
        }

        $cutoff = now()->subHours(48)->getTimestamp();
        foreach (Storage::disk('local')->directories($root) as $dir) {
            $metaRel = $dir.'/meta.json';
            if (! Storage::disk('local')->exists($metaRel)) {
                Storage::disk('local')->deleteDirectory($dir);
                continue;
            }
            $mtime = Storage::disk('local')->lastModified($metaRel);
            if (is_int($mtime) && $mtime < $cutoff) {
                Storage::disk('local')->deleteDirectory($dir);
            }
        }
    }

    protected function loadOwnedMeta(User $user, string $uploadId): array
    {
        $id = trim($uploadId);
        if ($id === '' || ! preg_match('/^[0-9a-fA-F-]{32,40}$/', $id)) {
            abort(404, 'Upload session not found.');
        }

        $meta = $this->readMeta($id);
        if ((int) ($meta['user_id'] ?? 0) !== (int) $user->id) {
            abort(404, 'Upload session not found.');
        }

        return $meta;
    }

    protected function readMeta(string $id): array
    {
        $path = $this->metaPath($id);
        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'Upload session not found.');
        }
        $decoded = json_decode((string) Storage::disk('local')->get($path), true);
        if (! is_array($decoded) || empty($decoded['id'])) {
            abort(404, 'Upload session not found.');
        }

        return $decoded;
    }

    protected function writeMeta(string $id, array $meta): void
    {
        Storage::disk('local')->put($this->metaPath($id), json_encode($meta, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  callable(array): array  $mutator
     */
    protected function mutateMeta(string $id, callable $mutator): array
    {
        $abs = Storage::disk('local')->path($this->metaPath($id));
        $fh = fopen($abs, 'c+');
        if ($fh === false) {
            abort(500, 'Could not update upload session.');
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $meta = json_decode(is_string($raw) ? $raw : '', true);
            if (! is_array($meta)) {
                $meta = $this->readMeta($id);
            }
            $meta = $mutator($meta);
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, json_encode($meta, JSON_UNESCAPED_SLASHES));
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        return $meta;
    }

    protected function deleteSession(string $id): void
    {
        Storage::disk('local')->deleteDirectory('video-uploads/'.$id);
    }

    protected function metaPath(string $id): string
    {
        return 'video-uploads/'.$id.'/meta.json';
    }

    protected function partsDir(string $id): string
    {
        return 'video-uploads/'.$id.'/parts';
    }

    protected function partPath(string $id, int $index): string
    {
        return $this->partsDir($id).'/'.$index;
    }

    protected function statusPayload(array $meta): array
    {
        $received = array_values(array_unique(array_map('intval', $meta['received'] ?? [])));
        sort($received, SORT_NUMERIC);

        return [
            'id' => $meta['id'],
            'sizeBytes' => (int) $meta['size_bytes'],
            'chunkSize' => (int) $meta['chunk_size'],
            'totalChunks' => (int) $meta['total_chunks'],
            'receivedChunks' => $received,
            'receivedBytes' => $this->receivedBytes($meta, $received),
        ];
    }

    /**
     * @param  list<int>  $received
     */
    protected function receivedBytes(array $meta, array $received): int
    {
        $chunkSize = (int) $meta['chunk_size'];
        $sizeBytes = (int) $meta['size_bytes'];
        $total = (int) $meta['total_chunks'];
        $bytes = 0;
        foreach ($received as $index) {
            $bytes += $index === $total - 1
                ? ($sizeBytes - ($chunkSize * ($total - 1)))
                : $chunkSize;
        }

        return (int) $bytes;
    }

    protected function detectVideoMime(string $absolutePath, string $fallback): string
    {
        $detected = is_file($absolutePath) ? (string) (@mime_content_type($absolutePath) ?: '') : '';
        if (str_starts_with(strtolower($detected), 'video/')) {
            return $detected;
        }
        $fallback = strtolower(trim($fallback));
        if (str_starts_with($fallback, 'video/')) {
            return $fallback;
        }

        return 'video/mp4';
    }
}
