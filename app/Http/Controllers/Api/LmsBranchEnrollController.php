<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchEnroll;
use App\Services\EnrollmentSchemaService;
use App\Services\LmsCatalogService;
use App\Support\SimpleXlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LmsBranchEnrollController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->filled('page')) {
            EnrollmentSchemaService::ensureBranchEnrollIdColumn();
            $validated = $request->validate([
                'page' => ['required', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            ]);

            $perPage = (int) ($validated['per_page'] ?? 10);
            $page = (int) $validated['page'];
            $search = isset($validated['search']) ? trim((string) $validated['search']) : '';

            $query = BranchEnroll::query()
                ->withCount('enrollments')
                ->orderBy('sort_order')
                ->orderByDesc('updated_at')
                ->orderByDesc('id');

            if ($search !== '') {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            }

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'data' => $paginator->getCollection()
                    ->map(fn (BranchEnroll $row) => $this->format($row))
                    ->values()
                    ->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem() ?? 0,
                    'to' => $paginator->lastItem() ?? 0,
                ],
            ]);
        }

        $rows = BranchEnroll::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (BranchEnroll $row) => $this->format($row));

        return response()->json(['data' => $rows]);
    }

    public function applicants(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        EnrollmentSchemaService::ensureBranchEnrollIdColumn();

        $branch = BranchEnroll::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'in:pending,approved,rejected,hold'],
            'program' => ['sometimes', 'nullable', 'string', 'max:64'],
            'batch' => ['sometimes', 'nullable', 'string', 'max:64'],
            'learningMode' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        return response()->json(
            $catalog->branchApplicantsPaginated(
                $branch,
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 10),
                isset($validated['search']) ? (string) $validated['search'] : null,
                isset($validated['status']) ? (string) $validated['status'] : null,
                isset($validated['program']) ? (string) $validated['program'] : null,
                isset($validated['batch']) ? (string) $validated['batch'] : null,
                isset($validated['learningMode']) ? (string) $validated['learningMode'] : null
            )
        );
    }

    public function exportApplicants(Request $request, string $publicId, LmsCatalogService $catalog)
    {
        EnrollmentSchemaService::ensureBranchEnrollIdColumn();

        $branch = BranchEnroll::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'in:pending,approved,rejected,hold'],
            'program' => ['sometimes', 'nullable', 'string', 'max:64'],
            'batch' => ['sometimes', 'nullable', 'string', 'max:64'],
            'learningMode' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $headers = $catalog->branchApplicantExportHeaders();
        $rows = $catalog->branchApplicantsForExport(
            $branch,
            isset($validated['search']) ? (string) $validated['search'] : null,
            isset($validated['status']) ? (string) $validated['status'] : null,
            isset($validated['program']) ? (string) $validated['program'] : null,
            isset($validated['batch']) ? (string) $validated['batch'] : null,
            isset($validated['learningMode']) ? (string) $validated['learningMode'] : null
        );
        $binary = SimpleXlsx::build('Applicants', $headers, $rows);
        $filename = 'branch-applicants-'.now()->format('Y-m-d').'.xlsx';

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
            'sortOrder' => ['sometimes', 'integer', 'min:1', 'max:65535'],
        ]);

        $name = trim((string) $validated['name']);

        $row = BranchEnroll::query()->create([
            'public_id' => $this->nextPublicId($name),
            'name' => $name,
            'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
            'status' => $validated['status'] ?? 'active',
            'sort_order' => isset($validated['sortOrder']) ? (int) $validated['sortOrder'] : 1,
        ]);

        return response()->json(['data' => $this->format($row)], 201);
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $row = BranchEnroll::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:active,inactive'],
            'sortOrder' => ['sometimes', 'integer', 'min:1', 'max:65535'],
        ]);

        if (array_key_exists('name', $validated)) {
            $row->name = trim((string) $validated['name']);
        }
        if (array_key_exists('description', $validated)) {
            $row->description = $validated['description'] !== null
                ? trim((string) $validated['description'])
                : null;
        }
        if (array_key_exists('status', $validated)) {
            $row->status = (string) $validated['status'];
        }
        if (array_key_exists('sortOrder', $validated)) {
            $row->sort_order = (int) $validated['sortOrder'];
        }

        $row->save();

        return response()->json(['data' => $this->format($row->fresh())]);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $row = BranchEnroll::query()->where('public_id', $publicId)->firstOrFail();
        $row->delete();

        return response()->json(['message' => 'Branch to enroll deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(BranchEnroll $row): array
    {
        return [
            'id' => $row->public_id,
            'name' => $row->name,
            'description' => $row->description,
            'status' => $row->status ?? 'active',
            'sortOrder' => (int) $row->sort_order,
            'applicantCount' => (int) ($row->enrollments_count ?? 0),
            'updatedAt' => $row->updated_at?->toIso8601String(),
        ];
    }

    private function nextPublicId(string $name): string
    {
        $base = 'branch-enroll-'.Str::slug($name !== '' ? $name : 'entry');
        if ($base === 'branch-enroll-') {
            $base = 'branch-enroll';
        }

        $publicId = $base;
        $suffix = 2;
        while (BranchEnroll::withTrashed()->where('public_id', $publicId)->exists()) {
            $publicId = $base.'-'.$suffix;
            $suffix++;
        }

        return $publicId;
    }
}
