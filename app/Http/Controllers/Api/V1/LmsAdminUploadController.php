<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\AdminUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LmsAdminUploadController extends Controller
{
    use ResolvesLmsActor;

    public function store(Request $request): JsonResponse
    {
        $assetType = $request->input('asset_type') ?? $request->input('assetType');

        $data = $request->validate([
            'title' => 'required|string|max:255',
        ]);

        if (! $assetType || ! is_string($assetType)) {
            return response()->json(['message' => 'The asset type field is required.'], 422);
        }

        if (strlen($assetType) > 64) {
            return response()->json(['message' => 'The asset type may not be greater than 64 characters.'], 422);
        }

        $user = $this->lmsActor();

        $upload = AdminUpload::query()->create([
            'public_id' => 'upload-'.Str::lower(Str::ulid()),
            'user_id' => $user->id,
            'title' => $data['title'],
            'asset_type' => $assetType,
            'status' => 'Queued',
        ]);

        return response()->json([
            'id' => $upload->public_id,
            'title' => $upload->title,
            'type' => $upload->asset_type,
            'status' => $upload->status,
        ], 201);
    }
}
