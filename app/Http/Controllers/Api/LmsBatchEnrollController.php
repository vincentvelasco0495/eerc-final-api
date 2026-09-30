<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BatchEnroll;
use App\Models\Program;
use App\Services\EnrollmentSchemaService;
use App\Services\LmsCatalogService;
use App\Support\SimpleXlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LmsBatchEnrollController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->filled('page')) {
            EnrollmentSchemaService::ensureBatchEnrollIdColumn();

            $validated = $request->validate([
                'page' => ['required', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            ]);

            $perPage = (int) ($validated['per_page'] ?? 10);
            $page = (int) $validated['page'];
            $search = isset($validated['search']) ? trim((string) $validated['search']) : '';

            $query = BatchEnroll::query()
                ->with('program:id,public_id,title')
                ->withCount('enrollments')
                ->orderBy('sort_order')
                ->orderByDesc('updated_at')
                ->orderByDesc('id');

            if ($search !== '') {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('tentative_start', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhereHas('program', function ($programQuery) use ($like) {
                            $programQuery->where('title', 'like', $like)
                                ->orWhere('code', 'like', $like);
                        });
                });
            }

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'data' => $paginator->getCollection()
                    ->map(fn (BatchEnroll $row) => $this->format($row))
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

        $rows = BatchEnroll::query()
            ->with('program:id,public_id,title')
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (BatchEnroll $row) => $this->format($row));

        return response()->json(['data' => $rows]);
    }

    public function applicants(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        EnrollmentSchemaService::ensureBatchEnrollIdColumn();

        $batch = BatchEnroll::query()->with('program')->where('public_id', $publicId)->firstOrFail();

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
            $catalog->batchApplicantsPaginated($batch, $page, $perPage, $search, $status)
        );
    }

    public function exportApplicants(Request $request, string $publicId, LmsCatalogService $catalog)
    {
        EnrollmentSchemaService::ensureBatchEnrollIdColumn();

        $batch = BatchEnroll::query()->with('program')->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'in:pending,approved,rejected,hold'],
        ]);
        $search = isset($validated['search']) ? (string) $validated['search'] : null;
        $status = isset($validated['status']) ? (string) $validated['status'] : null;

        $headers = $catalog->batchApplicantExportHeaders();
        $rows = $catalog->batchApplicantsForExport($batch, $search, $status);
        $binary = SimpleXlsx::build('Applicants', $headers, $rows);
        $filename = 'batch-applicants-'.now()->format('Y-m-d').'.xlsx';

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
            'programId' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'tentativeStart' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
            'sortOrder' => ['sometimes', 'integer', 'min:1', 'max:65535'],
        ]);

        $program = Program::query()->where('public_id', trim((string) $validated['programId']))->firstOrFail();

        $publicId = $this->nextPublicId(trim((string) $validated['name']));

        $row = BatchEnroll::query()->create([
            'public_id' => $publicId,
            'program_id' => $program->id,
            'name' => trim((string) $validated['name']),
            'label' => trim((string) $validated['name']),
            'code' => Str::upper(Str::slug(trim((string) $validated['name']), '_')),
            'tentative_start' => isset($validated['tentativeStart']) ? trim((string) $validated['tentativeStart']) : null,
            'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
            'status' => $validated['status'] ?? 'active',
            'sort_order' => isset($validated['sortOrder']) ? (int) $validated['sortOrder'] : 1,
        ]);

        $row->load('program:id,public_id,title');

        return response()->json(['data' => $this->format($row)], 201);
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $row = BatchEnroll::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'programId' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'tentativeStart' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:active,inactive'],
            'sortOrder' => ['sometimes', 'integer', 'min:1', 'max:65535'],
        ]);

        if (array_key_exists('programId', $validated)) {
            $program = Program::query()->where('public_id', trim((string) $validated['programId']))->firstOrFail();
            $row->program_id = $program->id;
        }
        if (array_key_exists('name', $validated)) {
            $row->name = trim((string) $validated['name']);
            $row->label = trim((string) $validated['name']);
        }
        if (array_key_exists('tentativeStart', $validated)) {
            $row->tentative_start = $validated['tentativeStart'] !== null
                ? trim((string) $validated['tentativeStart'])
                : null;
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
        $row->load('program:id,public_id,title');

        return response()->json(['data' => $this->format($row->fresh())]);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $row = BatchEnroll::query()->where('public_id', $publicId)->firstOrFail();
        $row->delete();

        return response()->json(['message' => 'Batch enroll deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(BatchEnroll $row): array
    {
        $displayName = $row->name ?: $row->label;

        return [
            'id' => $row->public_id,
            'programId' => $row->program?->public_id,
            'programTitle' => $row->program?->title,
            'name' => $displayName,
            'tentativeStart' => $row->tentative_start,
            'description' => $row->description,
            'status' => $row->status ?? 'active',
            'sortOrder' => (int) $row->sort_order,
            'applicantCount' => (int) ($row->enrollments_count ?? 0),
            'updatedAt' => $row->updated_at?->toIso8601String(),
        ];
    }

    private function nextPublicId(string $name): string
    {
        $base = 'batch-enroll-'.Str::slug($name !== '' ? $name : 'entry');
        if ($base === 'batch-enroll-') {
            $base = 'batch-enroll';
        }

        $publicId = $base;
        $suffix = 2;
        while (BatchEnroll::withTrashed()->where('public_id', $publicId)->exists()) {
            $publicId = $base.'-'.$suffix;
            $suffix++;
        }

        return $publicId;
    }
}
