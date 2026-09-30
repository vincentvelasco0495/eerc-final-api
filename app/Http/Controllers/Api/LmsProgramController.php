<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Services\LmsCatalogService;
use App\Support\PageAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LmsProgramController extends Controller
{
    public function index(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        if (! $request->filled('page')) {
            return response()->json(['data' => $catalog->programs()]);
        }

        if ($response = PageAuthorization::denyUnlessCanAccess($request, '/setting-program')) {
            return $response;
        }

        $validated = $request->validate([
            'page' => ['required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) $validated['page'];
        $search = isset($validated['search']) ? (string) $validated['search'] : null;

        $payload = $catalog->programsPaginated($page, $perPage, $search);

        return response()->json($payload);
    }

    public function stats(string $programPublicId, LmsCatalogService $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->programStats($programPublicId)]);
    }

    public function store(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'slug' => ['nullable', 'string', 'max:191'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
            'bannerPath' => ['nullable', 'string', 'max:2048'],
            'bannerImage' => ['sometimes', 'nullable', 'image', 'max:4096'],
            'enrollmentFee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $title = trim((string) $validated['title']);
        $code = trim((string) $validated['code']);
        $baseSlug = isset($validated['slug']) && trim((string) $validated['slug']) !== ''
            ? Str::slug(trim((string) $validated['slug']))
            : Str::slug($title !== '' ? $title : $code);
        if ($baseSlug === '') {
            $baseSlug = 'program';
        }
        $slug = $baseSlug;
        $slugSuffix = 2;
        while (Program::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$slugSuffix;
            $slugSuffix++;
        }

        $basePublicId = 'program-'.Str::slug($title !== '' ? $title : $code);
        if ($basePublicId === 'program-') {
            $basePublicId = 'program';
        }
        $publicId = $basePublicId;
        $suffix = 2;
        while (Program::withTrashed()->where('public_id', $publicId)->exists()) {
            $publicId = $basePublicId.'-'.$suffix;
            $suffix++;
        }

        $bannerPath = isset($validated['bannerPath']) && trim((string) $validated['bannerPath']) !== ''
            ? trim((string) $validated['bannerPath'])
            : null;
        if ($request->hasFile('bannerImage')) {
            $bannerPath = $this->storeBannerImage($request);
        }

        $program = Program::query()->create([
            'public_id' => $publicId,
            'code' => $code,
            'slug' => $slug,
            'title' => $title,
            'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
            'enrollment_fee' => $this->resolveEnrollmentFee($validated['enrollmentFee'] ?? null),
            'status' => $validated['status'] ?? 'active',
            'banner_path' => $bannerPath,
        ]);

        return response()->json(['data' => $catalog->programPayload($program)], 201);
    }

    public function applicants(Request $request, string $programPublicId, LmsCatalogService $catalog): JsonResponse
    {
        $program = Program::query()->where('public_id', $programPublicId)->firstOrFail();

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'in:pending,approved,rejected,hold'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);
        $search = isset($validated['search']) ? (string) $validated['search'] : null;
        $status = isset($validated['status']) ? (string) $validated['status'] : null;

        return response()->json(
            $catalog->programApplicantsPaginated($program, $page, $perPage, $search, $status)
        );
    }

    public function update(Request $request, string $programPublicId, LmsCatalogService $catalog): JsonResponse
    {
        $program = Program::query()->where('public_id', $programPublicId)->firstOrFail();

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:16'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:191'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:active,inactive'],
            'bannerPath' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'bannerImage' => ['sometimes', 'nullable', 'image', 'max:4096'],
            'enrollmentFee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        if (array_key_exists('code', $validated)) {
            $program->code = trim((string) $validated['code']);
        }
        if (array_key_exists('slug', $validated)) {
            $incomingSlug = $validated['slug'];
            if ($incomingSlug === null || trim((string) $incomingSlug) === '') {
                $program->slug = null;
            } else {
                $normalizedSlug = Str::slug(trim((string) $incomingSlug));
                if ($normalizedSlug === '') {
                    $normalizedSlug = 'program';
                }
                $baseSlug = $normalizedSlug;
                $slug = $baseSlug;
                $suffix = 2;
                while (Program::withTrashed()
                    ->where('slug', $slug)
                    ->where('id', '!=', $program->id)
                    ->exists()) {
                    $slug = $baseSlug.'-'.$suffix;
                    $suffix++;
                }
                $program->slug = $slug;
            }
        }
        if (array_key_exists('title', $validated)) {
            $program->title = trim((string) $validated['title']);
        }
        if (array_key_exists('description', $validated)) {
            $program->description = $validated['description'] !== null ? trim((string) $validated['description']) : null;
        }
        if (array_key_exists('status', $validated)) {
            $program->status = (string) $validated['status'];
        }
        if (array_key_exists('enrollmentFee', $validated)) {
            $program->enrollment_fee = $this->resolveEnrollmentFee($validated['enrollmentFee']);
        }
        if (array_key_exists('bannerPath', $validated)) {
            $program->banner_path = $validated['bannerPath'] !== null && trim((string) $validated['bannerPath']) !== ''
                ? trim((string) $validated['bannerPath'])
                : null;
        }
        if ($request->hasFile('bannerImage')) {
            if (is_string($program->banner_path) && $program->banner_path !== '' && ! str_starts_with($program->banner_path, 'http')) {
                Storage::disk('public')->delete($program->banner_path);
            }
            $program->banner_path = $this->storeBannerImage($request);
        }

        $program->save();

        return response()->json(['data' => $catalog->programPayload($program->fresh())]);
    }

    public function destroy(string $programPublicId): JsonResponse
    {
        $program = Program::query()->where('public_id', $programPublicId)->firstOrFail();

        if ($program->courses()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a program that still has courses.',
            ], 422);
        }

        $program->delete();

        return response()->json(['message' => 'Program deleted.']);
    }

    private function storeBannerImage(Request $request): ?string
    {
        $file = $request->file('bannerImage');
        if (! $file) {
            return null;
        }

        return Storage::disk('public')->putFile('program-banners', $file);
    }

    private function resolveEnrollmentFee(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $fee = round((float) $value, 2);

        return $fee >= 0 ? $fee : null;
    }
}
