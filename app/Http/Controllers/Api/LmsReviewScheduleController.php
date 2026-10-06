<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchEnroll;
use App\Models\Enrollment;
use App\Models\ReviewSchedule;
use App\Services\EnrollmentSchemaService;
use App\Services\LmsCatalogService;
use App\Support\ExcelDownload;
use App\Support\ExportDateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LmsReviewScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->filled('page')) {
            EnrollmentSchemaService::ensureReviewScheduleIdColumn();
            $validated = $request->validate([
                'page' => ['required', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            ]);

            $perPage = (int) ($validated['per_page'] ?? 10);
            $page = (int) $validated['page'];
            $search = isset($validated['search']) ? trim((string) $validated['search']) : '';

            $query = ReviewSchedule::query()
                ->with('branchEnroll:id,public_id,name')
                ->withCount('enrollments')
                ->withCount([
                    'enrollments as occupied_seats_count' => fn ($q) => $q->where('status', Enrollment::STATUS_APPROVED),
                ])
                ->orderBy('sort_order')
                ->orderByDesc('updated_at')
                ->orderByDesc('id');

            if ($search !== '') {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('description', 'like', $like);

                    if (Schema::hasColumn('review_schedules', 'label')) {
                        $q->orWhere('label', 'like', $like);
                    }

                    $q->orWhereHas('branchEnroll', function ($branchQuery) use ($like) {
                        $branchQuery->where('name', 'like', $like);
                    });
                });
            }

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'data' => $paginator->getCollection()
                    ->map(fn (ReviewSchedule $row) => $this->format($row))
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

        $rows = ReviewSchedule::query()
            ->with('branchEnroll:id,public_id,name')
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ReviewSchedule $row) => $this->format($row));

        return response()->json(['data' => $rows]);
    }

    public function export(Request $request)
    {
        EnrollmentSchemaService::ensureReviewScheduleIdColumn();

        $validated = $request->validate(array_merge(ExportDateRange::rules(), [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]));
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        [$from, $to] = ExportDateRange::extract($validated);

        $query = ReviewSchedule::query()
            ->with('branchEnroll:id,public_id,name')
            ->orderBy('sort_order')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('branchEnroll', fn ($branchQuery) => $branchQuery->where('name', 'like', $like));
            });
        }

        ExportDateRange::constrain($query, $from, $to);

        $rows = $query->get()->map(fn (ReviewSchedule $row) => [
            $row->branchEnroll?->name ?? '',
            $row->name ?? '',
            (int) ($row->student_capacity ?? 0),
            $row->status ?? 'active',
            (int) $row->sort_order,
            ExcelDownload::plain($row->description),
        ])->values()->all();

        return ExcelDownload::make(
            'Schedules',
            ['Branch', 'Schedule', 'Capacity', 'Status', 'Sort order', 'Description'],
            $rows,
            'review-schedules'
        );
    }

    public function applicants(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        EnrollmentSchemaService::ensureReviewScheduleIdColumn();

        $schedule = ReviewSchedule::query()
            ->with('branchEnroll:id,public_id,name')
            ->where('public_id', $publicId)
            ->firstOrFail();

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
            $catalog->reviewScheduleApplicantsPaginated(
                $schedule,
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
        EnrollmentSchemaService::ensureReviewScheduleIdColumn();

        $schedule = ReviewSchedule::query()
            ->with('branchEnroll:id,public_id,name')
            ->where('public_id', $publicId)
            ->firstOrFail();

        $validated = $request->validate(array_merge(ExportDateRange::rules(), [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'in:pending,approved,rejected,hold'],
            'program' => ['sometimes', 'nullable', 'string', 'max:64'],
            'batch' => ['sometimes', 'nullable', 'string', 'max:64'],
            'learningMode' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]));
        [$from, $to] = ExportDateRange::extract($validated);

        $headers = $catalog->reviewScheduleApplicantExportHeaders();
        $rows = $catalog->reviewScheduleApplicantsForExport(
            $schedule,
            isset($validated['search']) ? (string) $validated['search'] : null,
            isset($validated['status']) ? (string) $validated['status'] : null,
            isset($validated['program']) ? (string) $validated['program'] : null,
            isset($validated['batch']) ? (string) $validated['batch'] : null,
            isset($validated['learningMode']) ? (string) $validated['learningMode'] : null,
            $from,
            $to
        );

        return ExcelDownload::make('Applicants', $headers, $rows, 'review-schedule-applicants');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branchEnrollId' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
            'sortOrder' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'studentCapacity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $name = trim((string) $validated['name']);
        $branchId = $this->resolveBranchEnrollId((string) $validated['branchEnrollId']);

        $row = ReviewSchedule::query()->create([
            'public_id' => $this->nextPublicId($name),
            'name' => $name,
            'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
            'status' => $validated['status'] ?? 'active',
            'sort_order' => isset($validated['sortOrder']) ? (int) $validated['sortOrder'] : 1,
            'enrollment_branch_id' => $branchId,
            'student_capacity' => (int) $validated['studentCapacity'],
        ]);

        if (Schema::hasColumn('review_schedules', 'label')) {
            $row->label = $name;
            $row->save();
        }

        $row->load('branchEnroll:id,public_id,name');

        return response()->json(['data' => $this->format($row)], 201);
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $row = ReviewSchedule::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'branchEnrollId' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:active,inactive'],
            'sortOrder' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'studentCapacity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);

        if (array_key_exists('branchEnrollId', $validated)) {
            $row->enrollment_branch_id = $this->resolveBranchEnrollId((string) $validated['branchEnrollId']);
        }
        if (array_key_exists('name', $validated)) {
            $name = trim((string) $validated['name']);
            $row->name = $name;

            if (Schema::hasColumn('review_schedules', 'label')) {
                $row->label = $name;
            }
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
        if (array_key_exists('studentCapacity', $validated)) {
            $row->student_capacity = (int) $validated['studentCapacity'];
        }

        $row->save();
        $row->load('branchEnroll:id,public_id,name');

        return response()->json(['data' => $this->format($row->fresh())]);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $row = ReviewSchedule::query()->where('public_id', $publicId)->firstOrFail();
        $row->delete();

        return response()->json(['message' => 'Review schedule deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(ReviewSchedule $row): array
    {
        return [
            'id' => $row->public_id,
            'branchEnrollId' => $row->branchEnroll?->public_id,
            'branchName' => $row->branchEnroll?->name,
            'name' => $row->name,
            'description' => $row->description,
            'status' => $row->status ?? 'active',
            'sortOrder' => (int) $row->sort_order,
            'studentCapacity' => (int) ($row->student_capacity ?? 0),
            'applicantCount' => (int) ($row->enrollments_count ?? 0),
            'occupiedSeats' => (int) ($row->occupied_seats_count ?? 0),
            'updatedAt' => $row->updated_at?->toIso8601String(),
        ];
    }

    private function resolveBranchEnrollId(string $publicId): int
    {
        $branch = BranchEnroll::query()
            ->where('public_id', trim($publicId))
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages([
                'branchEnrollId' => ['Selected branch was not found.'],
            ]);
        }

        return (int) $branch->id;
    }

    private function nextPublicId(string $name): string
    {
        $base = 'review-schedule-'.Str::slug($name !== '' ? $name : 'entry');
        if ($base === 'review-schedule-') {
            $base = 'review-schedule';
        }

        $publicId = $base;
        $suffix = 2;
        while (ReviewSchedule::withTrashed()->where('public_id', $publicId)->exists()) {
            $publicId = $base.'-'.$suffix;
            $suffix++;
        }

        return $publicId;
    }
}
