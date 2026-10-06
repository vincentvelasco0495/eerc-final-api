<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BatchEnroll;
use App\Models\BranchEnroll;
use App\Models\HonorAwardDiscount;
use App\Models\LearningMode;
use App\Models\PackageEnroll;
use App\Models\Program;
use App\Models\ReviewSchedule;
use App\Support\HttpCache;
use App\Support\LmsCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LmsEnrollmentFormOptionsController extends Controller
{
    public function show(Request $request): JsonResponse|Response
    {
        $programPublicId = trim((string) $request->query('programId', ''));
        $payload = LmsCache::remember(
            LmsCache::enrollOptionsKey($programPublicId),
            LmsCache::OPTIONS_TTL,
            fn () => $this->buildPayload($programPublicId)
        );

        return HttpCache::json($payload, 60, true);
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    private function buildPayload(string $programPublicId): array
    {
        $batchQuery = BatchEnroll::query()
            ->with('program')
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($programPublicId !== '') {
            $program = Program::query()->where('public_id', $programPublicId)->first();
            if ($program) {
                $batchQuery->where('program_id', $program->id);
            } else {
                $batchQuery->whereRaw('1 = 0');
            }
        }

        $batchEnrolls = $batchQuery
            ->get()
            ->map(fn (BatchEnroll $row) => [
                'id' => $row->public_id,
                'programId' => $row->program?->public_id ?? null,
                'name' => $row->name ?? $row->label ?? '',
                'tentativeStart' => $row->tentative_start,
                'description' => $row->description,
                'sortOrder' => (int) $row->sort_order,
            ])
            ->values()
            ->all();

        $reviewSchedules = ReviewSchedule::query()
            ->with('branchEnroll')
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ReviewSchedule $row) => [
                'id' => $row->public_id,
                'branchEnrollId' => $row->branchEnroll?->public_id,
                'branchName' => $row->branchEnroll?->name ?? $row->branchEnroll?->label ?? null,
                'name' => $row->name,
                'description' => $row->description,
                'studentCapacity' => (int) ($row->student_capacity ?? 30),
                'sortOrder' => (int) $row->sort_order,
            ])
            ->values()
            ->all();

        return [
            'data' => [
                'batchEnrolls' => $batchEnrolls,
                'learningModes' => $this->formatSimpleOptions(LearningMode::class),
                'branchEnrolls' => $this->formatSimpleOptions(BranchEnroll::class),
                'reviewSchedules' => $reviewSchedules,
                'honorAwardDiscounts' => $this->formatSimpleOptions(HonorAwardDiscount::class),
                'packageEnrolls' => $this->formatSimpleOptions(PackageEnroll::class),
            ],
        ];
    }

    /**
     * @param  class-string  $modelClass
     * @return array<int, array<string, mixed>>
     */
    private function formatSimpleOptions(string $modelClass): array
    {
        return $modelClass::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->public_id,
                'name' => $row->name ?? $row->label ?? '',
                'description' => $row->description,
                'sortOrder' => (int) $row->sort_order,
            ])
            ->values()
            ->all();
    }
}
