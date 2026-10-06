<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PackageEnroll;
use App\Services\EnrollmentSchemaService;
use App\Services\LmsCatalogService;
use App\Support\ExcelDownload;
use App\Support\ExportDateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LmsPackageEnrollController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->filled('page')) {
            EnrollmentSchemaService::ensurePackageEnrollIdColumn();
            $validated = $request->validate([
                'page' => ['required', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            ]);

            $perPage = (int) ($validated['per_page'] ?? 10);
            $page = (int) $validated['page'];
            $search = isset($validated['search']) ? trim((string) $validated['search']) : '';

            $query = PackageEnroll::query()
                ->withCount('enrollments')
                ->orderBy('sort_order')
                ->orderByDesc('updated_at')
                ->orderByDesc('id');

            if ($search !== '') {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('description', 'like', $like);

                    if (Schema::hasColumn('package_enrolls', 'label')) {
                        $q->orWhere('label', 'like', $like);
                    }
                });
            }

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'data' => $paginator->getCollection()
                    ->map(fn (PackageEnroll $row) => $this->format($row))
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

        $rows = PackageEnroll::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (PackageEnroll $row) => $this->format($row));

        return response()->json(['data' => $rows]);
    }

    public function export(Request $request)
    {
        EnrollmentSchemaService::ensurePackageEnrollIdColumn();

        $validated = $request->validate(array_merge(ExportDateRange::rules(), [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]));
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        [$from, $to] = ExportDateRange::extract($validated);

        $query = PackageEnroll::query()
            ->orderBy('sort_order')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like);

                if (Schema::hasColumn('package_enrolls', 'label')) {
                    $q->orWhere('label', 'like', $like);
                }
            });
        }

        ExportDateRange::constrain($query, $from, $to);

        $rows = $query->get()->map(fn (PackageEnroll $row) => [
            $row->name ?? '',
            $row->status ?? 'active',
            (int) $row->sort_order,
            ExcelDownload::plain($row->description),
        ])->values()->all();

        return ExcelDownload::make(
            'Packages',
            ['Name', 'Status', 'Sort order', 'Description'],
            $rows,
            'package-enrolls'
        );
    }

    public function applicants(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        EnrollmentSchemaService::ensurePackageEnrollIdColumn();

        $package = PackageEnroll::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'in:pending,approved,rejected,hold'],
            'program' => ['sometimes', 'nullable', 'string', 'max:64'],
            'batch' => ['sometimes', 'nullable', 'string', 'max:64'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        return response()->json(
            $catalog->packageEnrollApplicantsPaginated(
                $package,
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 10),
                isset($validated['search']) ? (string) $validated['search'] : null,
                isset($validated['status']) ? (string) $validated['status'] : null,
                isset($validated['program']) ? (string) $validated['program'] : null,
                isset($validated['batch']) ? (string) $validated['batch'] : null,
                isset($validated['branch']) ? (string) $validated['branch'] : null
            )
        );
    }

    public function exportApplicants(Request $request, string $publicId, LmsCatalogService $catalog)
    {
        EnrollmentSchemaService::ensurePackageEnrollIdColumn();

        $package = PackageEnroll::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate(array_merge(ExportDateRange::rules(), [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'in:pending,approved,rejected,hold'],
            'program' => ['sometimes', 'nullable', 'string', 'max:64'],
            'batch' => ['sometimes', 'nullable', 'string', 'max:64'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]));
        [$from, $to] = ExportDateRange::extract($validated);

        $headers = $catalog->packageEnrollApplicantExportHeaders();
        $rows = $catalog->packageEnrollApplicantsForExport(
            $package,
            isset($validated['search']) ? (string) $validated['search'] : null,
            isset($validated['status']) ? (string) $validated['status'] : null,
            isset($validated['program']) ? (string) $validated['program'] : null,
            isset($validated['batch']) ? (string) $validated['batch'] : null,
            isset($validated['branch']) ? (string) $validated['branch'] : null,
            $from,
            $to
        );

        return ExcelDownload::make('Applicants', $headers, $rows, 'package-enroll-applicants');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:512'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
            'sortOrder' => ['sometimes', 'integer', 'min:1', 'max:65535'],
        ]);

        $name = trim((string) $validated['name']);

        $row = PackageEnroll::query()->create([
            'public_id' => $this->nextPublicId($name),
            'name' => $name,
            'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
            'status' => $validated['status'] ?? 'active',
            'sort_order' => isset($validated['sortOrder']) ? (int) $validated['sortOrder'] : 1,
        ]);

        if (Schema::hasColumn('package_enrolls', 'label')) {
            $row->label = $name;
            $row->save();
        }

        return response()->json(['data' => $this->format($row)], 201);
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $row = PackageEnroll::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:512'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:active,inactive'],
            'sortOrder' => ['sometimes', 'integer', 'min:1', 'max:65535'],
        ]);

        if (array_key_exists('name', $validated)) {
            $name = trim((string) $validated['name']);
            $row->name = $name;

            if (Schema::hasColumn('package_enrolls', 'label')) {
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

        $row->save();

        return response()->json(['data' => $this->format($row->fresh())]);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $row = PackageEnroll::query()->where('public_id', $publicId)->firstOrFail();
        $row->delete();

        return response()->json(['message' => 'Package enroll option deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(PackageEnroll $row): array
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
        $base = 'package-enroll-'.Str::slug($name !== '' ? $name : 'entry');
        if ($base === 'package-enroll-') {
            $base = 'package-enroll';
        }

        $publicId = $base;
        $suffix = 2;
        while (PackageEnroll::withTrashed()->where('public_id', $publicId)->exists()) {
            $publicId = $base.'-'.$suffix;
            $suffix++;
        }

        return $publicId;
    }
}
