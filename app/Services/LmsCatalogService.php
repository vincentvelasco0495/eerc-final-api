<?php

namespace App\Services;

use App\Models\AdminUpload;
use App\Models\Assignment;
use App\Models\AssignmentAttempt;
use App\Models\AssignmentQuestion;
use App\Models\AssignmentQuestionOption;
use App\Models\BatchEnroll;
use App\Models\BranchEnroll;
use App\Models\CmsMedia;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\HonorAwardDiscount;
use App\Models\LeaderboardEntry;
use App\Models\LearningMode;
use App\Models\LessonMaterial;
use App\Models\Module;
use App\Models\ModuleResource;
use App\Models\PackageEnroll;
use App\Models\Program;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\ReviewSchedule;
use App\Models\Student;
use App\Models\User;
use App\Models\UserLessonProgress;
use App\Models\UserModuleProgress;
use App\Support\ExportDateRange;
use App\Support\LessonMetaSupport;
use App\Support\LmsCache;
use App\Support\LmsMeta;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Domain queries and serializers for modular LMS API endpoints (no combined bootstrap payload).
 */
class LmsCatalogService
{
    /** @var array<string, array<string, bool>> */
    protected array $curriculumLockMapCache = [];

    public function __construct(
        protected string $actorPublicUid = 'learner-01'
    ) {
        $this->actorPublicUid = (string) config('lms.actor_public_uid', $this->actorPublicUid);
    }

    public function resolveActor(): User
    {
        return User::query()
            ->with(['lmsProfile.program', 'badges'])
            ->where('public_uid', $this->actorPublicUid)
            ->firstOrFail();
    }

    public function meta(): array
    {
        return LmsCache::remember(LmsCache::META, 60, function () {
            return [
                'todayLabel' => now()->format('M j, Y'),
                'leaderboardPeriods' => LmsMeta::LEADERBOARD_PERIODS,
                'learningFlowSteps' => LmsMeta::LEARNING_FLOW_STEPS,
            ];
        });
    }

    public function userPayload(User $user): array
    {
        $payload = $this->formatActor($user);
        $payload['pagePermissions'] = app(PagePermissionService::class)
            ->permissionsPayloadForRole((string) $user->role);

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    public function programs(): array
    {
        return LmsCache::remember(LmsCache::PROGRAMS, LmsCache::CATALOG_TTL, function () {
            return Program::query()->orderBy('title')->get()
                ->map(fn (Program $p) => $this->formatProgram($p))
                ->all();
        });
    }

    /**
     * Paginated program catalog (admin list). Ordered by latest activity.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function programsPaginated(int $page, int $perPage, ?string $search = null): array
    {
        $query = Program::query()
            ->select([
                'id',
                'public_id',
                'code',
                'slug',
                'title',
                'description',
                'enrollment_fee',
                'status',
                'banner_path',
                'created_at',
                'updated_at',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('slug', 'like', $like);
            });
        }

        /** @var LengthAwarePaginator<int, Program> $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->getCollection()
                ->map(fn (Program $p) => $this->formatProgram($p))
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
        ];
    }

    /**
     * Applicants for one program (all statuses unless filtered). Search is name/email only.
     *
     * @return array{program: array<string, mixed>, applicantCount: int, data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function programApplicantsPaginated(
        Program $program,
        int $page,
        int $perPage,
        ?string $search = null,
        ?string $status = null
    ): array {
        $query = Enrollment::query()
            ->with(['program', 'course', 'user.studentProfile'])
            ->where('program_id', $program->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        $statusFilter = $status !== null ? strtolower(trim($status)) : '';
        if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'hold'], true)) {
            $query->where('status', $statusFilter);
        }

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->whereHas('user', function ($userQuery) use ($like) {
                $userQuery->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        /** @var LengthAwarePaginator<int, Enrollment> $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'program' => [
                'id' => $program->public_id,
                'code' => $program->code,
                'title' => $program->title,
            ],
            'applicantCount' => Enrollment::query()->where('program_id', $program->id)->count(),
            'data' => $paginator->getCollection()
                ->map(function (Enrollment $e) {
                    $row = $this->formatEnrollment($e, true);
                    $applied = $e->submitted_at ?? $e->created_at;
                    $row['appliedAt'] = optional($applied)->format('Y-m-d');

                    return $row;
                })
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
        ];
    }

    /**
     * Applicants for one batch (all statuses unless filtered). Scoped by batch unique ID + program.
     *
     * @return array{batch: array<string, mixed>, applicantCount: int, data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function batchApplicantsPaginated(
        BatchEnroll $batch,
        int $page,
        int $perPage,
        ?string $search = null,
        ?string $status = null
    ): array {
        $paginator = $this->batchApplicantsQuery($batch, $search, $status)
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'batch' => $this->formatBatchApplicantContext($batch),
            'applicantCount' => $this->batchApplicantCount($batch),
            'data' => $paginator->getCollection()
                ->map(fn (Enrollment $e) => $this->formatApplicantRow($e))
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
        ];
    }

    /**
     * @return list<string>
     */
    public function batchApplicantExportHeaders(): array
    {
        return [
            'Applicant name',
            'Email',
            'Application date',
            'Application status',
            'Program',
            'Batch',
            'Tentative start',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function batchApplicantsForExport(
        BatchEnroll $batch,
        ?string $search = null,
        ?string $status = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $batch->loadMissing('program');

        $query = $this->batchApplicantsQuery($batch, $search, $status);
        ExportDateRange::constrainCoalesce($query, $from, $to);

        return $query
            ->get()
            ->map(function (Enrollment $e) use ($batch) {
                $applied = $e->submitted_at ?? $e->created_at;

                return [
                    $e->user->name ?? '',
                    $e->user->email ?? '',
                    optional($applied)->format('Y-m-d') ?? '',
                    $e->status ?? '',
                    $batch->program?->title ?? '',
                    $batch->name ?: ($batch->label ?? ''),
                    $batch->tentative_start ?? '',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatBatchApplicantContext(BatchEnroll $batch): array
    {
        $batch->loadMissing('program');

        return [
            'id' => $batch->public_id,
            'name' => $batch->name ?: ($batch->label ?? ''),
            'tentativeStart' => $batch->tentative_start,
            'programId' => $batch->program?->public_id,
            'programTitle' => $batch->program?->title ?? '',
        ];
    }

    protected function batchApplicantCount(BatchEnroll $batch): int
    {
        return Enrollment::query()
            ->where('batch_enroll_id', $batch->id)
            ->where('program_id', $batch->program_id)
            ->count();
    }

    protected function batchApplicantsQuery(
        BatchEnroll $batch,
        ?string $search = null,
        ?string $status = null
    ): Builder {
        $query = Enrollment::query()
            ->with(['program', 'course', 'batchEnroll', 'user.studentProfile'])
            ->where('batch_enroll_id', $batch->id)
            ->where('program_id', $batch->program_id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        $statusFilter = $status !== null ? strtolower(trim($status)) : '';
        if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'hold'], true)) {
            $query->where('status', $statusFilter);
        }

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->whereHas('user', function ($userQuery) use ($like) {
                $userQuery->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatApplicantRow(Enrollment $e): array
    {
        $e->loadMissing(['program', 'course', 'batchEnroll', 'learningMode', 'branchEnroll', 'user.studentProfile']);

        $row = $this->formatEnrollment($e, true);
        $applied = $e->submitted_at ?? $e->created_at;
        $row['appliedAt'] = optional($applied)->format('Y-m-d');
        $row['batchId'] = $e->batchEnroll?->public_id;
        $row['batchName'] = $e->batchEnroll?->name ?: ($e->batchEnroll?->label ?? '');
        $row['learningModeId'] = $e->learningMode?->public_id;
        $row['learningModeName'] = $e->learningMode?->name ?: ($e->learningMode?->label ?? '');
        $row['branchId'] = $e->branchEnroll?->public_id;
        $row['branchName'] = $e->branchEnroll?->name ?: ($e->branchEnroll?->label ?? '');
        $row['discountProofUploaded'] = $this->enrollmentHasDiscountProof($e);
        $row['discountProofStatus'] = $row['discountProofUploaded'] ? 'uploaded' : 'not_uploaded';
        $row['paymentStatus'] = $this->enrollmentPaymentStatus($e, $row);

        return $row;
    }

    /**
     * Applicants for one learning mode (all statuses unless filtered). Scoped by unique ID, never by name.
     *
     * @return array{learningMode: array<string, mixed>, applicantCount: int, filters: array<string, mixed>, data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function learningModeApplicantsPaginated(
        LearningMode $mode,
        int $page,
        int $perPage,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null
    ): array {
        $paginator = $this->learningModeApplicantsQuery(
            $mode,
            $search,
            $status,
            $programPublicId,
            $batchPublicId
        )->paginate($perPage, ['*'], 'page', $page);

        return [
            'learningMode' => [
                'id' => $mode->public_id,
                'name' => $mode->name,
            ],
            'applicantCount' => $this->learningModeApplicantCount($mode),
            'filters' => $this->applicantFilterOptions(),
            'data' => $paginator->getCollection()
                ->map(fn (Enrollment $e) => $this->formatApplicantRow($e))
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
        ];
    }

    /**
     * @return list<string>
     */
    public function learningModeApplicantExportHeaders(): array
    {
        return [
            'Applicant name',
            'Email',
            'Program',
            'Enrollment batch',
            'Application date',
            'Application status',
            'Learning mode',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function learningModeApplicantsForExport(
        LearningMode $mode,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $query = $this->learningModeApplicantsQuery(
            $mode,
            $search,
            $status,
            $programPublicId,
            $batchPublicId
        );
        ExportDateRange::constrainCoalesce($query, $from, $to);

        return $query
            ->get()
            ->map(function (Enrollment $e) use ($mode) {
                $applied = $e->submitted_at ?? $e->created_at;

                return [
                    $e->user->name ?? '',
                    $e->user->email ?? '',
                    $e->program?->title ?? '',
                    $e->batchEnroll?->name ?: ($e->batchEnroll?->label ?? ''),
                    optional($applied)->format('Y-m-d') ?? '',
                    $e->status ?? '',
                    $mode->name ?? '',
                ];
            })
            ->values()
            ->all();
    }

    protected function learningModeApplicantCount(LearningMode $mode): int
    {
        return Enrollment::query()->where('learning_mode_id', $mode->id)->count();
    }

    protected function learningModeApplicantsQuery(
        LearningMode $mode,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null
    ): Builder {
        $query = Enrollment::query()
            ->with(['program', 'course', 'batchEnroll', 'user.studentProfile'])
            ->where('learning_mode_id', $mode->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        $programHint = $programPublicId !== null ? trim($programPublicId) : '';
        if ($programHint !== '') {
            $program = Program::query()->where('public_id', $programHint)->first();
            if ($program === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('program_id', $program->id);
            }
        }

        $batchHint = $batchPublicId !== null ? trim($batchPublicId) : '';
        if ($batchHint !== '') {
            $batch = BatchEnroll::query()->where('public_id', $batchHint)->first();
            if ($batch === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('batch_enroll_id', $batch->id)
                    ->where('program_id', $batch->program_id);
            }
        }

        $statusFilter = $status !== null ? strtolower(trim($status)) : '';
        if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'hold'], true)) {
            $query->where('status', $statusFilter);
        }

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->whereHas('user', function ($userQuery) use ($like) {
                $userQuery->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @return array{programs: list<array<string, mixed>>, batches: list<array<string, mixed>>, learningModes: list<array<string, mixed>>}
     */
    protected function applicantFilterOptions(): array
    {
        return [
            'programs' => Program::query()
                ->orderBy('title')
                ->get(['public_id', 'title'])
                ->map(fn (Program $program) => [
                    'id' => $program->public_id,
                    'title' => $program->title,
                ])
                ->values()
                ->all(),
            'batches' => BatchEnroll::query()
                ->with('program:id,public_id')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (BatchEnroll $batch) => [
                    'id' => $batch->public_id,
                    'name' => $batch->name ?: ($batch->label ?? ''),
                    'programId' => $batch->program?->public_id,
                ])
                ->values()
                ->all(),
            'learningModes' => LearningMode::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['public_id', 'name'])
                ->map(fn (LearningMode $mode) => [
                    'id' => $mode->public_id,
                    'name' => $mode->name,
                ])
                ->values()
                ->all(),
            'branches' => BranchEnroll::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['public_id', 'name'])
                ->map(fn (BranchEnroll $branch) => [
                    'id' => $branch->public_id,
                    'name' => $branch->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Applicants for one branch (all statuses unless filtered). Scoped by unique ID, never by name or address.
     *
     * @return array{branch: array<string, mixed>, applicantCount: int, filters: array<string, mixed>, data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function branchApplicantsPaginated(
        BranchEnroll $branch,
        int $page,
        int $perPage,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $learningModePublicId = null
    ): array {
        $paginator = $this->branchApplicantsQuery(
            $branch,
            $search,
            $status,
            $programPublicId,
            $batchPublicId,
            $learningModePublicId
        )->paginate($perPage, ['*'], 'page', $page);

        return [
            'branch' => [
                'id' => $branch->public_id,
                'name' => $branch->name,
            ],
            'applicantCount' => $this->branchApplicantCount($branch),
            'filters' => $this->applicantFilterOptions(),
            'data' => $paginator->getCollection()
                ->map(fn (Enrollment $e) => $this->formatApplicantRow($e))
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
        ];
    }

    /**
     * @return list<string>
     */
    public function branchApplicantExportHeaders(): array
    {
        return [
            'Applicant name',
            'Email',
            'Program',
            'Enrollment batch',
            'Learning mode',
            'Application date',
            'Application status',
            'Branch',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function branchApplicantsForExport(
        BranchEnroll $branch,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $learningModePublicId = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $query = $this->branchApplicantsQuery(
            $branch,
            $search,
            $status,
            $programPublicId,
            $batchPublicId,
            $learningModePublicId
        );
        ExportDateRange::constrainCoalesce($query, $from, $to);

        return $query
            ->get()
            ->map(function (Enrollment $e) use ($branch) {
                $applied = $e->submitted_at ?? $e->created_at;

                return [
                    $e->user->name ?? '',
                    $e->user->email ?? '',
                    $e->program?->title ?? '',
                    $e->batchEnroll?->name ?: ($e->batchEnroll?->label ?? ''),
                    $e->learningMode?->name ?: ($e->learningMode?->label ?? ''),
                    optional($applied)->format('Y-m-d') ?? '',
                    $e->status ?? '',
                    $branch->name ?? '',
                ];
            })
            ->values()
            ->all();
    }

    protected function branchApplicantCount(BranchEnroll $branch): int
    {
        return Enrollment::query()->where('branch_enroll_id', $branch->id)->count();
    }

    protected function branchApplicantsQuery(
        BranchEnroll $branch,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $learningModePublicId = null
    ): Builder {
        $query = Enrollment::query()
            ->with(['program', 'course', 'batchEnroll', 'learningMode', 'user.studentProfile'])
            ->where('branch_enroll_id', $branch->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        $programHint = $programPublicId !== null ? trim($programPublicId) : '';
        if ($programHint !== '') {
            $program = Program::query()->where('public_id', $programHint)->first();
            if ($program === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('program_id', $program->id);
            }
        }

        $batchHint = $batchPublicId !== null ? trim($batchPublicId) : '';
        if ($batchHint !== '') {
            $batch = BatchEnroll::query()->where('public_id', $batchHint)->first();
            if ($batch === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('batch_enroll_id', $batch->id)
                    ->where('program_id', $batch->program_id);
            }
        }

        $modeHint = $learningModePublicId !== null ? trim($learningModePublicId) : '';
        if ($modeHint !== '') {
            $mode = LearningMode::query()->where('public_id', $modeHint)->first();
            if ($mode === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('learning_mode_id', $mode->id);
            }
        }

        $statusFilter = $status !== null ? strtolower(trim($status)) : '';
        if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'hold'], true)) {
            $query->where('status', $statusFilter);
        }

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->whereHas('user', function ($userQuery) use ($like) {
                $userQuery->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * Applicants for one review schedule (all statuses unless filtered).
     * Scoped by unique schedule ID (and its associated branch), never by label or time.
     *
     * @return array{reviewSchedule: array<string, mixed>, applicantCount: int, occupiedSeats: int, remainingSeats: int, filters: array<string, mixed>, data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function reviewScheduleApplicantsPaginated(
        ReviewSchedule $schedule,
        int $page,
        int $perPage,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $learningModePublicId = null
    ): array {
        $schedule->loadMissing('branchEnroll');
        $capacity = (int) ($schedule->student_capacity ?? 0);
        $occupied = $this->reviewScheduleOccupiedSeats($schedule);
        $paginator = $this->reviewScheduleApplicantsQuery(
            $schedule,
            $search,
            $status,
            $programPublicId,
            $batchPublicId,
            $learningModePublicId
        )->paginate($perPage, ['*'], 'page', $page);

        return [
            'reviewSchedule' => [
                'id' => $schedule->public_id,
                'name' => $schedule->name,
                'branchEnrollId' => $schedule->branchEnroll?->public_id,
                'branchName' => $schedule->branchEnroll?->name,
                'studentCapacity' => $capacity,
            ],
            'applicantCount' => $this->reviewScheduleApplicantCount($schedule),
            'occupiedSeats' => $occupied,
            'remainingSeats' => max(0, $capacity - $occupied),
            'filters' => $this->applicantFilterOptions(),
            'data' => $paginator->getCollection()
                ->map(fn (Enrollment $e) => $this->formatApplicantRow($e))
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
        ];
    }

    /**
     * @return list<string>
     */
    public function reviewScheduleApplicantExportHeaders(): array
    {
        return [
            'Applicant name',
            'Email',
            'Program',
            'Enrollment batch',
            'Learning mode',
            'Application date',
            'Application status',
            'Review schedule',
            'Branch',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function reviewScheduleApplicantsForExport(
        ReviewSchedule $schedule,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $learningModePublicId = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $schedule->loadMissing('branchEnroll');

        $query = $this->reviewScheduleApplicantsQuery(
            $schedule,
            $search,
            $status,
            $programPublicId,
            $batchPublicId,
            $learningModePublicId
        );
        ExportDateRange::constrainCoalesce($query, $from, $to);

        return $query
            ->get()
            ->map(function (Enrollment $e) use ($schedule) {
                $applied = $e->submitted_at ?? $e->created_at;

                return [
                    $e->user->name ?? '',
                    $e->user->email ?? '',
                    $e->program?->title ?? '',
                    $e->batchEnroll?->name ?: ($e->batchEnroll?->label ?? ''),
                    $e->learningMode?->name ?: ($e->learningMode?->label ?? ''),
                    optional($applied)->format('Y-m-d') ?? '',
                    $e->status ?? '',
                    $schedule->name ?? '',
                    $schedule->branchEnroll?->name ?? '',
                ];
            })
            ->values()
            ->all();
    }

    protected function reviewScheduleApplicantCount(ReviewSchedule $schedule): int
    {
        return Enrollment::query()->where('review_schedule_id', $schedule->id)->count();
    }

    /**
     * Confirmed seats use the same rule as course access: approved applications only.
     */
    protected function reviewScheduleOccupiedSeats(ReviewSchedule $schedule): int
    {
        return Enrollment::query()
            ->where('review_schedule_id', $schedule->id)
            ->where('status', Enrollment::STATUS_APPROVED)
            ->count();
    }

    protected function reviewScheduleApplicantsQuery(
        ReviewSchedule $schedule,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $learningModePublicId = null
    ): Builder {
        $query = Enrollment::query()
            ->with(['program', 'course', 'batchEnroll', 'learningMode', 'user.studentProfile'])
            ->where('review_schedule_id', $schedule->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        if ($schedule->enrollment_branch_id) {
            $query->where(function ($branchQuery) use ($schedule) {
                $branchQuery->where('branch_enroll_id', $schedule->enrollment_branch_id)
                    ->orWhereNull('branch_enroll_id');
            });
        }

        $programHint = $programPublicId !== null ? trim($programPublicId) : '';
        if ($programHint !== '') {
            $program = Program::query()->where('public_id', $programHint)->first();
            if ($program === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('program_id', $program->id);
            }
        }

        $batchHint = $batchPublicId !== null ? trim($batchPublicId) : '';
        if ($batchHint !== '') {
            $batch = BatchEnroll::query()->where('public_id', $batchHint)->first();
            if ($batch === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('batch_enroll_id', $batch->id)
                    ->where('program_id', $batch->program_id);
            }
        }

        $modeHint = $learningModePublicId !== null ? trim($learningModePublicId) : '';
        if ($modeHint !== '') {
            $mode = LearningMode::query()->where('public_id', $modeHint)->first();
            if ($mode === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('learning_mode_id', $mode->id);
            }
        }

        $statusFilter = $status !== null ? strtolower(trim($status)) : '';
        if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'hold'], true)) {
            $query->where('status', $statusFilter);
        }

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->whereHas('user', function ($userQuery) use ($like) {
                $userQuery->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * Applicants for one honors/awards/discount option. Scoped by unique ID.
     * "None" is a stored option ID and does not include missing selections.
     *
     * @return array{honorAwardDiscount: array<string, mixed>, applicantCount: int, filters: array<string, mixed>, data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function honorAwardDiscountApplicantsPaginated(
        HonorAwardDiscount $option,
        int $page,
        int $perPage,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $branchPublicId = null
    ): array {
        $paginator = $this->honorAwardDiscountApplicantsQuery(
            $option,
            $search,
            $status,
            $programPublicId,
            $batchPublicId,
            $branchPublicId
        )->paginate($perPage, ['*'], 'page', $page);

        return [
            'honorAwardDiscount' => [
                'id' => $option->public_id,
                'name' => $option->name,
            ],
            'applicantCount' => $this->honorAwardDiscountApplicantCount($option),
            'filters' => $this->applicantFilterOptions(),
            'data' => $paginator->getCollection()
                ->map(fn (Enrollment $e) => $this->formatApplicantRow($e))
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
        ];
    }

    /**
     * @return list<string>
     */
    public function honorAwardDiscountApplicantExportHeaders(): array
    {
        return [
            'Applicant name',
            'Email',
            'Program',
            'Enrollment batch',
            'Branch',
            'Application date',
            'Application status',
            'Discount proof',
            'Honors / awards / discount',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function honorAwardDiscountApplicantsForExport(
        HonorAwardDiscount $option,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $branchPublicId = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $query = $this->honorAwardDiscountApplicantsQuery(
            $option,
            $search,
            $status,
            $programPublicId,
            $batchPublicId,
            $branchPublicId
        );
        ExportDateRange::constrainCoalesce($query, $from, $to);

        return $query
            ->get()
            ->map(function (Enrollment $e) use ($option) {
                $applied = $e->submitted_at ?? $e->created_at;

                return [
                    $e->user->name ?? '',
                    $e->user->email ?? '',
                    $e->program?->title ?? '',
                    $e->batchEnroll?->name ?: ($e->batchEnroll?->label ?? ''),
                    $e->branchEnroll?->name ?: ($e->branchEnroll?->label ?? ''),
                    optional($applied)->format('Y-m-d') ?? '',
                    $e->status ?? '',
                    $this->enrollmentHasDiscountProof($e) ? 'Uploaded' : 'Not uploaded',
                    $option->name ?? '',
                ];
            })
            ->values()
            ->all();
    }

    protected function honorAwardDiscountApplicantCount(HonorAwardDiscount $option): int
    {
        return Enrollment::query()->where('honor_award_discount_id', $option->id)->count();
    }

    protected function enrollmentHasDiscountProof(Enrollment $e): bool
    {
        $documents = is_array($e->documents) ? $e->documents : [];
        $proof = $documents['discountProof'] ?? null;

        return is_array($proof) && trim((string) ($proof['path'] ?? '')) !== '';
    }

    protected function honorAwardDiscountApplicantsQuery(
        HonorAwardDiscount $option,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $branchPublicId = null
    ): Builder {
        $query = Enrollment::query()
            ->with(['program', 'course', 'batchEnroll', 'learningMode', 'branchEnroll', 'user.studentProfile'])
            ->where('honor_award_discount_id', $option->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        $programHint = $programPublicId !== null ? trim($programPublicId) : '';
        if ($programHint !== '') {
            $program = Program::query()->where('public_id', $programHint)->first();
            if ($program === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('program_id', $program->id);
            }
        }

        $batchHint = $batchPublicId !== null ? trim($batchPublicId) : '';
        if ($batchHint !== '') {
            $batch = BatchEnroll::query()->where('public_id', $batchHint)->first();
            if ($batch === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('batch_enroll_id', $batch->id)
                    ->where('program_id', $batch->program_id);
            }
        }

        $branchHint = $branchPublicId !== null ? trim($branchPublicId) : '';
        if ($branchHint !== '') {
            $branch = BranchEnroll::query()->where('public_id', $branchHint)->first();
            if ($branch === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('branch_enroll_id', $branch->id);
            }
        }

        $statusFilter = $status !== null ? strtolower(trim($status)) : '';
        if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'hold'], true)) {
            $query->where('status', $statusFilter);
        }

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->whereHas('user', function ($userQuery) use ($like) {
                $userQuery->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * Applicants for one package enroll option. Scoped by unique ID, never by similar names.
     *
     * @return array{packageEnroll: array<string, mixed>, applicantCount: int, filters: array<string, mixed>, data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function packageEnrollApplicantsPaginated(
        PackageEnroll $package,
        int $page,
        int $perPage,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $branchPublicId = null
    ): array {
        $paginator = $this->packageEnrollApplicantsQuery(
            $package,
            $search,
            $status,
            $programPublicId,
            $batchPublicId,
            $branchPublicId
        )->paginate($perPage, ['*'], 'page', $page);

        return [
            'packageEnroll' => [
                'id' => $package->public_id,
                'name' => $package->name,
            ],
            'applicantCount' => $this->packageEnrollApplicantCount($package),
            'filters' => $this->applicantFilterOptions(),
            'data' => $paginator->getCollection()
                ->map(fn (Enrollment $e) => $this->formatApplicantRow($e))
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
        ];
    }

    /**
     * @return list<string>
     */
    public function packageEnrollApplicantExportHeaders(): array
    {
        return [
            'Applicant name',
            'Email',
            'Program',
            'Enrollment batch',
            'Branch',
            'Learning mode',
            'Application date',
            'Application status',
            'Payment status',
            'Package',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function packageEnrollApplicantsForExport(
        PackageEnroll $package,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $branchPublicId = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $query = $this->packageEnrollApplicantsQuery(
            $package,
            $search,
            $status,
            $programPublicId,
            $batchPublicId,
            $branchPublicId
        );
        ExportDateRange::constrainCoalesce($query, $from, $to);

        return $query
            ->get()
            ->map(function (Enrollment $e) use ($package) {
                $row = $this->formatApplicantRow($e);
                $applied = $e->submitted_at ?? $e->created_at;

                return [
                    $e->user->name ?? '',
                    $e->user->email ?? '',
                    $e->program?->title ?? '',
                    $e->batchEnroll?->name ?: ($e->batchEnroll?->label ?? ''),
                    $e->branchEnroll?->name ?: ($e->branchEnroll?->label ?? ''),
                    $e->learningMode?->name ?: ($e->learningMode?->label ?? ''),
                    optional($applied)->format('Y-m-d') ?? '',
                    $e->status ?? '',
                    $this->paymentStatusLabel((string) ($row['paymentStatus'] ?? 'none')),
                    $package->name ?? '',
                ];
            })
            ->values()
            ->all();
    }

    protected function packageEnrollApplicantCount(PackageEnroll $package): int
    {
        return Enrollment::query()->where('package_enroll_id', $package->id)->count();
    }

    /**
     * Existing payment verification only — not scholarship/eligibility approval.
     *
     * @param  array<string, mixed>  $row
     */
    protected function enrollmentPaymentStatus(Enrollment $e, array $row): string
    {
        if (! empty($row['hasUnreviewedPayments'])) {
            return 'pending';
        }

        $status = strtolower(trim((string) ($row['downpaymentVerificationStatus'] ?? '')));
        if (in_array($status, ['pending', 'correct', 'invalid'], true)) {
            return $status;
        }

        if (! empty($row['hasPaymentProof']) || ! empty($row['totalPaid']) || $e->payment_proof_path) {
            return 'pending';
        }

        return 'none';
    }

    protected function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'correct' => 'Correct',
            'invalid' => 'Invalid',
            'pending' => 'Pending review',
            default => 'None',
        };
    }

    protected function packageEnrollApplicantsQuery(
        PackageEnroll $package,
        ?string $search = null,
        ?string $status = null,
        ?string $programPublicId = null,
        ?string $batchPublicId = null,
        ?string $branchPublicId = null
    ): Builder {
        $query = Enrollment::query()
            ->with(['program', 'course', 'batchEnroll', 'learningMode', 'branchEnroll', 'user.studentProfile'])
            ->where('package_enroll_id', $package->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        $programHint = $programPublicId !== null ? trim($programPublicId) : '';
        if ($programHint !== '') {
            $program = Program::query()->where('public_id', $programHint)->first();
            if ($program === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('program_id', $program->id);
            }
        }

        $batchHint = $batchPublicId !== null ? trim($batchPublicId) : '';
        if ($batchHint !== '') {
            $batch = BatchEnroll::query()->where('public_id', $batchHint)->first();
            if ($batch === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('batch_enroll_id', $batch->id)
                    ->where('program_id', $batch->program_id);
            }
        }

        $branchHint = $branchPublicId !== null ? trim($branchPublicId) : '';
        if ($branchHint !== '') {
            $branch = BranchEnroll::query()->where('public_id', $branchHint)->first();
            if ($branch === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('branch_enroll_id', $branch->id);
            }
        }

        $statusFilter = $status !== null ? strtolower(trim($status)) : '';
        if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'hold'], true)) {
            $query->where('status', $statusFilter);
        }

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->whereHas('user', function ($userQuery) use ($like) {
                $userQuery->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        return $query;
    }

    public function programPayload(Program $program): array
    {
        return $this->formatProgram($program);
    }

    /**
     * Public stats for one program (safe for guests).
     * Only **published** catalog courses are counted so totals match the learner catalog
     * (e.g. Available programs cards and `/courses` published listings).
     *
     * @return array{programId: string, totalCourses: int, totalDurationHours: int, totalLectures: int, totalVideos: int, totalQuizzes: int}
     */
    public function programStats(string $programPublicId): array
    {
        /** @var Program $program */
        return LmsCache::remember(LmsCache::statsKey('program', $programPublicId), LmsCache::STATS_TTL, function () use ($programPublicId) {
        $program = Program::query()->where('public_id', $programPublicId)->firstOrFail();

        $courses = Course::query()
            ->where('program_id', $program->id)
            ->where('is_published', true)
            ->with(['modules'])
            ->get();

        $courseIds = $courses->pluck('id');

        $totalCourses = (int) $courses->count();

        $totalDurationHours = (int) $courses->sum('hours');

        $visibleModules = $courses->flatMap(fn (Course $course) => $this->modulesForCourseStats($course));
        $moduleIds = $visibleModules->pluck('id')->unique()->values();

        $totalLectures = (int) $moduleIds->count();

        $totalQuizzes = (int) Quiz::query()
            ->whereIn('course_id', $courseIds)
            ->count();

        $totalVideos = $this->countVideoLessonsForModules($visibleModules);

        return [
            'programId' => $program->public_id,
            'totalCourses' => $totalCourses,
            'totalDurationHours' => $totalDurationHours,
            'totalLectures' => $totalLectures,
            'totalVideos' => $totalVideos,
            'totalQuizzes' => $totalQuizzes,
        ];
        });
    }

    /**
     * Public aggregate stats for one course (safe for guests).
     *
     * @return array{courseId: string, totalDurationHours: int, totalLectures: int, totalVideos: int, totalQuizzes: int, level: string}
     */
    public function courseStats(string $coursePublicId): array
    {
        /** @var Course $course */
        return LmsCache::remember(LmsCache::statsKey('course', $coursePublicId), LmsCache::STATS_TTL, function () use ($coursePublicId) {
        $course = Course::query()
            ->with(['modules.resources'])
            ->where('public_id', $coursePublicId)
            ->firstOrFail();

        $modulesStat = $this->modulesForCourseStats($course);
        $moduleIds = $modulesStat->pluck('id');

        $totalQuizzes = (int) Quiz::query()
            ->where('course_id', $course->id)
            ->when($moduleIds->isNotEmpty(), function ($query) use ($moduleIds) {
                $query->where(function ($q) use ($moduleIds) {
                    $q->whereIn('module_id', $moduleIds)->orWhereNull('module_id');
                });
            })
            ->count();

        $totalAssignments = (int) Assignment::query()
            ->where('course_id', $course->id)
            ->when($moduleIds->isNotEmpty(), function ($query) use ($moduleIds) {
                $query->whereIn('module_id', $moduleIds);
            })
            ->count();

        $totalVideos = $this->countVideoLessonsForModules($modulesStat);

        return [
            'courseId' => $course->public_id,
            'totalDurationHours' => (int) $course->hours,
            'totalLectures' => (int) $modulesStat->count(),
            'totalVideos' => $totalVideos,
            'totalQuizzes' => $totalQuizzes,
            'totalAssignments' => $totalAssignments,
            'level' => (string) ($course->level ?? ''),
        ];
        });
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function coursesPaginated(
        User $user,
        int $page,
        int $perPage,
        ?string $programHint = null,
        ?string $status = null
    ): array {
        $completedMods = UserModuleProgress::query()
            ->where('user_id', $user->id)
            ->where('progress_percent', '>=', 100)
            ->pluck('module_id');

        $programHint = trim((string) ($programHint ?? ''));
        $programAliasMap = [
            'civil-engineering' => 'program-ce',
            'civil_engineering' => 'program-ce',
            'civil-engineing' => 'program-ce',
            'civil_engineing' => 'program-ce',
            'civilengineering' => 'program-ce',
            'ce' => 'program-ce',
            'master-plumbing' => 'program-plumbing',
            'master_plumbing' => 'program-plumbing',
            'masterplumbing' => 'program-plumbing',
            'mpl' => 'program-plumbing',
            'materials-engineering' => 'program-materials',
            'materials_engineering' => 'program-materials',
            'materialsengineering' => 'program-materials',
            'mse' => 'program-materials',
        ];
        $normalizedProgramHint = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $programHint) ?? '');
        $normalizedProgramHint = trim($normalizedProgramHint, '-');
        $resolvedProgramPublicId = $programAliasMap[$normalizedProgramHint] ?? null;

        $status = strtolower(trim((string) ($status ?? '')));

        /** @var LengthAwarePaginator $paginator */
        $paginator = Course::query()
            ->with([
                'tags',
                'subjects',
                'program',
                'nextModule',
                'modules',
            ])
            ->when($programHint !== '', function ($query) use ($programHint, $normalizedProgramHint, $resolvedProgramPublicId) {
                $query->whereHas('program', function ($programQuery) use ($programHint, $normalizedProgramHint, $resolvedProgramPublicId) {
                    $programQuery->where('public_id', $programHint)
                        ->orWhereRaw('LOWER(code) = ?', [strtolower($programHint)])
                        ->orWhereRaw('LOWER(slug) = ?', [strtolower($programHint)])
                        ->orWhereRaw('LOWER(title) = ?', [str_replace('-', ' ', $normalizedProgramHint)]);

                    if ($resolvedProgramPublicId !== null) {
                        $programQuery->orWhere('public_id', $resolvedProgramPublicId);
                    }
                });
            })
            ->when($status === 'published', fn ($query) => $query->where('is_published', true))
            ->when($status === 'draft', fn ($query) => $query->where('is_published', false))
            ->when($status === 'upcoming', fn ($query) => $query->whereRaw('0 = 1'))
            ->orderByDesc('updated_at')
            ->orderBy('title')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = $paginator->getCollection()
            ->map(fn (Course $c) => $this->formatCourse($c, $user, $completedMods))
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'limit' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function enrollmentsForUser(User $user): array
    {
        return Enrollment::query()
            ->with(['program', 'course', 'user.studentProfile'])
            ->where('user_id', $user->id)
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn (Enrollment $e) => $this->formatEnrollment($e))
            ->all();
    }

    /**
     * Published catalog courses the learner may access from approved course enrollments.
     *
     * @return array<int, array<string, mixed>>
     */
    public function enrolledCoursesForUser(User $user): array
    {
        if ($user->id <= 0) {
            return [];
        }

        $completedMods = UserModuleProgress::query()
            ->where('user_id', $user->id)
            ->where('progress_percent', '>=', 100)
            ->pluck('module_id');

        $enrollments = Enrollment::query()
            ->with(['program', 'course.program', 'course.tags', 'course.subjects', 'course.nextModule', 'course.modules'])
            ->where('user_id', $user->id)
            ->approved()
            ->whereNotNull('course_id')
            ->get();

        $courseModels = collect();

        foreach ($enrollments as $enrollment) {
            if ($enrollment->course && $enrollment->course->is_published) {
                $courseModels->push($enrollment->course);
            }
        }

        return $courseModels
            ->unique('id')
            ->values()
            ->map(fn (Course $c) => $this->formatCourse($c, $user, $completedMods))
            ->all();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function enrollmentsPaginated(
        int $page,
        int $perPage,
        ?string $search = null,
        ?int $userId = null,
        ?string $coursePublicId = null,
        ?string $status = null,
        bool $groupByLearner = false
    ): array {
        $query = $this->enrollmentsIndexQuery($search, $userId, $coursePublicId, $status);

        if ($groupByLearner && ($userId === null || $userId <= 0)) {
            return $this->enrollmentsPaginatedGroupedByLearner($query, $page, $perPage);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->getCollection()
                ->map(fn (Enrollment $e) => $this->formatEnrollment($e, true))
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
        ];
    }

    /**
     * @return list<string>
     */
    public function enrollmentExportHeaders(): array
    {
        return [
            'Enrollment ID',
            'Learner',
            'Email',
            'Phone',
            'School',
            'Program',
            'Course',
            'Status',
            'Submitted',
            'Full name',
            'Alias name',
            'Application school',
            'Gender',
            'Date of birth',
            'Contact number',
            'Home address',
            'Batch enrolled',
            'Mode of learning',
            'Branch',
            'Review schedule',
            'Honors / awards / discount',
            'Board exam experience',
            'Retaker attempts',
            'Downpayment',
            'Package',
            'Payment proof',
            'Total paid',
            'Unreviewed payments',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function enrollmentsForExport(?string $search = null, ?string $from = null, ?string $to = null): array
    {
        $query = $this->enrollmentsIndexQuery($search);
        ExportDateRange::constrainCoalesce($query, $from, $to);

        return $query
            ->get()
            ->map(fn (Enrollment $e) => $this->formatEnrollmentExportRow($e))
            ->values()
            ->all();
    }

    /**
     * @return list<list<string>>
     */
    public function programsForExport(?string $search = null, ?string $from = null, ?string $to = null): array
    {
        $query = Program::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('slug', 'like', $like);
            });
        }

        ExportDateRange::constrain($query, $from, $to);

        return $query->get()
            ->map(fn (Program $p) => [
                $p->code ?? '',
                $p->slug ?? '',
                $p->title ?? '',
                $p->enrollment_fee !== null ? (string) (float) $p->enrollment_fee : '',
                $p->status ?? 'active',
            ])
            ->values()
            ->all();
    }

    protected function enrollmentsIndexQuery(
        ?string $search = null,
        ?int $userId = null,
        ?string $coursePublicId = null,
        ?string $status = null
    ): Builder {
        $query = Enrollment::query()
            ->with(['program', 'course', 'user.studentProfile'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        if ($userId !== null && $userId > 0) {
            $query->where('user_id', $userId);
        }

        $courseHint = $coursePublicId !== null ? trim($coursePublicId) : '';
        if ($courseHint !== '') {
            $course = Course::query()->where('public_id', $courseHint)->first();
            if ($course === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('course_id', $course->id);
            }
        }

        $statusFilter = $status !== null ? strtolower(trim($status)) : '';
        if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'hold'], true)) {
            $query->where('status', $statusFilter);
        }

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->where('public_id', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhereHas('user', function ($userQuery) use ($like) {
                        $userQuery->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhereHas('studentProfile', function ($studentQuery) use ($like) {
                                $studentQuery->where('phone_number', 'like', $like)
                                    ->orWhere('school_held', 'like', $like);
                            });
                    })
                    ->orWhereHas('program', function ($programQuery) use ($like) {
                        $programQuery->where('title', 'like', $like)
                            ->orWhere('code', 'like', $like)
                            ->orWhere('public_id', 'like', $like);
                    })
                    ->orWhereHas('course', function ($courseQuery) use ($like) {
                        $courseQuery->where('title', 'like', $like)
                            ->orWhere('slug', 'like', $like)
                            ->orWhere('public_id', 'like', $like);
                    });
            });
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    protected function formatEnrollmentExportRow(Enrollment $e): array
    {
        $e->loadMissing(['program', 'course', 'user.studentProfile']);

        $student = $e->user?->studentProfile;
        $formData = is_array($e->form_data) ? $e->form_data : [];
        $summary = \App\Support\EnrollmentPayments::summarizeFormDataPayments($formData);
        $labels = is_array($formData['labels'] ?? null) ? $formData['labels'] : [];

        $gender = match (strtolower(trim((string) ($formData['gender'] ?? '')))) {
            'female' => 'Female',
            'male' => 'Male',
            default => (string) ($formData['gender'] ?? ''),
        };
        $examExperience = match (strtolower(trim((string) ($formData['examExperience'] ?? '')))) {
            'retaker' => 'Retaker',
            'first-time' => 'First-time taker',
            default => (string) ($formData['examExperience'] ?? ''),
        };

        return [
            (string) $e->public_id,
            (string) ($e->user?->name ?? ''),
            (string) ($e->user?->email ?? ''),
            (string) ($student?->phone_number ?? ''),
            (string) ($student?->school_held ?? ''),
            (string) ($e->program?->title ?? ''),
            (string) ($e->course?->title ?? ($e->course_id === null ? 'All courses' : '')),
            (string) $e->status,
            (string) optional($e->submitted_at)->format('Y-m-d'),
            (string) ($formData['fullName'] ?? ''),
            (string) ($formData['aliasName'] ?? ''),
            (string) ($formData['schoolName'] ?? ''),
            $gender,
            (string) ($formData['dateOfBirth'] ?? ''),
            (string) ($formData['contactNumber'] ?? ''),
            (string) ($formData['homeAddress'] ?? ''),
            (string) ($labels['batchEnroll'] ?? $formData['batchEnrollId'] ?? ''),
            (string) ($labels['learningMode'] ?? $formData['learningModeId'] ?? ''),
            (string) ($labels['branchEnroll'] ?? $formData['branchEnrollId'] ?? ''),
            (string) ($labels['reviewSchedule'] ?? $formData['reviewScheduleId'] ?? ''),
            (string) ($labels['honorAwardDiscount'] ?? $formData['honorAwardDiscountId'] ?? ''),
            $examExperience,
            (string) ($formData['retakerAttempts'] ?? ''),
            (string) ($formData['downpaymentAmount'] ?? ''),
            (string) ($labels['packageEnroll'] ?? $formData['packageEnrollId'] ?? ''),
            $e->payment_proof_path ? 'Yes' : 'No',
            $summary['verifiedTotal'] > 0 ? (string) $summary['verifiedTotal'] : '',
            ! empty($summary['hasUnreviewed']) ? 'Yes' : 'No',
        ];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    protected function enrollmentsPaginatedGroupedByLearner($query, int $page, int $perPage): array
    {
        $enrollments = $query->get();

        $groups = $enrollments
            ->groupBy('user_id')
            ->map(function ($items, $userId) {
                $sorted = $items
                    ->sortByDesc(fn (Enrollment $e) => $e->submitted_at?->getTimestamp() ?? 0)
                    ->values();
                $formatted = $sorted
                    ->map(fn (Enrollment $e) => $this->formatEnrollment($e, true))
                    ->values()
                    ->all();
                $primary = $formatted[0] ?? [];

                $programs = $sorted
                    ->map(fn (Enrollment $e) => $e->program?->title ?? '')
                    ->filter()
                    ->unique()
                    ->values();
                $courses = $sorted
                    ->filter(fn (Enrollment $e) => $e->course_id !== null && $e->course)
                    ->map(fn (Enrollment $e) => $e->course->title ?? '')
                    ->filter()
                    ->unique()
                    ->values();
                $hasProgramWide = $sorted->contains(fn (Enrollment $e) => $e->course_id === null);

                $courseLabel = '—';
                if ($courses->isNotEmpty() && $hasProgramWide) {
                    $courseLabel = $courses->implode(', ').', Program enrollment';
                } elseif ($courses->isNotEmpty()) {
                    $courseLabel = $courses->implode(', ');
                } elseif ($hasProgramWide) {
                    $courseLabel = 'Program enrollment';
                }

                return [
                    'id' => 'learner-'.$userId,
                    'userId' => (int) $userId,
                    'userName' => $primary['userName'] ?? '',
                    'userEmail' => $primary['userEmail'] ?? '',
                    'phoneNumber' => $primary['phoneNumber'] ?? '',
                    'schoolHeld' => $primary['schoolHeld'] ?? '',
                    'programTitle' => $programs->implode(', '),
                    'courseTitle' => $courseLabel,
                    'submittedAt' => $primary['submittedAt'] ?? '',
                    'status' => $this->summarizeEnrollmentStatuses($sorted),
                    'statusSummary' => $this->enrollmentStatusSummary($sorted),
                    'hasPaymentProof' => $sorted->contains(fn (Enrollment $e) => (bool) $e->payment_proof_path),
                    'hasUnreviewedPayments' => collect($formatted)->contains(
                        fn (array $item) => ! empty($item['hasUnreviewedPayments'])
                    ),
                    'enrollmentCount' => $sorted->count(),
                    'enrollments' => $formatted,
                    'isLearnerGroup' => true,
                ];
            })
            ->sortByDesc(fn (array $group) => $group['submittedAt'] ?? '')
            ->values();

        $total = $groups->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min(max(1, $page), $lastPage);
        $offset = ($currentPage - 1) * $perPage;
        $slice = $groups->slice($offset, $perPage)->values()->all();
        $from = $total === 0 ? 0 : $offset + 1;
        $to = $total === 0 ? 0 : min($offset + $perPage, $total);

        return [
            'data' => $slice,
            'meta' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    /**
     * Paginated payment history across enrollments (admin).
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function enrollmentPaymentsPaginated(
        int $page,
        int $perPage,
        ?string $search = null,
        ?string $verification = null
    ): array {
        $rows = $this->enrollmentPaymentRows($search, $verification);

        usort($rows, fn (array $a, array $b) => strcmp((string) ($b['paidAt'] ?? ''), (string) ($a['paidAt'] ?? '')));

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min(max(1, $page), $lastPage);
        $offset = ($currentPage - 1) * $perPage;
        $slice = array_slice($rows, $offset, $perPage);
        $from = $total === 0 ? 0 : $offset + 1;
        $to = $total === 0 ? 0 : min($offset + $perPage, $total);

        return [
            'data' => $slice,
            'meta' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function enrollmentPaymentsForExport(
        ?string $search = null,
        ?string $verification = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $rows = ExportDateRange::filterAssocRows(
            $this->enrollmentPaymentRows($search, $verification),
            $from,
            $to,
            'paidAt'
        );

        usort($rows, fn (array $a, array $b) => strcmp((string) ($b['paidAt'] ?? ''), (string) ($a['paidAt'] ?? '')));

        return array_map(static fn (array $row) => [
            (string) ($row['userName'] ?? ''),
            (string) ($row['userEmail'] ?? ''),
            (string) ($row['programTitle'] ?? ''),
            (string) ($row['label'] ?? ''),
            (string) ($row['amount'] ?? ''),
            (string) ($row['paidAt'] ?? ''),
            match ((string) ($row['verificationStatus'] ?? '')) {
                'correct' => 'Correct',
                'invalid' => 'Invalid',
                default => 'Pending',
            },
        ], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function enrollmentPaymentRows(?string $search = null, ?string $verification = null): array
    {
        $enrollments = Enrollment::query()
            ->with(['program', 'user'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        $rows = [];
        foreach ($enrollments as $enrollment) {
            $formData = is_array($enrollment->form_data) ? $enrollment->form_data : [];
            $paymentRows = \App\Support\EnrollmentPayments::paymentHistoryRowsForEnrollment(
                (string) $enrollment->public_id,
                $formData,
                optional($enrollment->submitted_at)->format('Y-m-d'),
                (bool) $enrollment->payment_proof_path,
                $enrollment->payment_proof_original_name,
                (string) ($enrollment->user->name ?? ''),
                (string) ($enrollment->user->email ?? ''),
                (string) ($enrollment->program?->title ?? ''),
                (string) ($enrollment->program?->public_id ?? '')
            );
            foreach ($paymentRows as $row) {
                $rows[] = $row;
            }
        }

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $needle = strtolower($term);
            $rows = array_values(array_filter($rows, function (array $row) use ($needle) {
                $haystack = strtolower(implode(' ', [
                    $row['userName'] ?? '',
                    $row['userEmail'] ?? '',
                    $row['programTitle'] ?? '',
                    $row['label'] ?? '',
                    $row['paymentId'] ?? '',
                    $row['enrollmentId'] ?? '',
                ]));

                return str_contains($haystack, $needle);
            }));
        }

        $verificationFilter = $verification !== null ? strtolower(trim($verification)) : '';
        if (in_array($verificationFilter, ['pending', 'correct', 'invalid'], true)) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => (string) ($row['verificationStatus'] ?? '') === $verificationFilter
            ));
        }

        return $rows;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Enrollment>  $enrollments
     */
    protected function summarizeEnrollmentStatuses($enrollments): string
    {
        $statuses = $enrollments->pluck('status')->unique()->values();
        if ($statuses->count() === 1) {
            return (string) $statuses->first();
        }

        return 'mixed';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Enrollment>  $enrollments
     * @return array<int, array{status: string, count: int}>
     */
    protected function enrollmentStatusSummary($enrollments): array
    {
        return $enrollments
            ->groupBy('status')
            ->map(fn ($items, $status) => [
                'status' => (string) $status,
                'count' => $items->count(),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function modulesForCourse(User $user, string $coursePublicId): array
    {
        $course = Course::query()->where('public_id', $coursePublicId)->with([
            'modules.resources.lessonMaterials.moduleResource',
            'modules.moduleLessonMaterials.moduleResource',
            'modules.course',
            'modules.quizzes' => fn ($query) => $query->withCount('questions'),
            'modules.assignments' => fn ($query) => $query->withCount('questions')->with('lessonMaterials'),
        ])->firstOrFail();

        $formatted = $course->modules
            ->sortBy(fn ($m) => sprintf('%05d', $m->sort_order))
            ->values()
            ->map(fn ($m) => $this->formatModule($m, $user))
            ->all();

        return $this->lockModulesForAccessLevel($formatted, $user, $course);
    }

    /**
     * @param  array<int, string>  $publicIds
     * @return array<int, array<string, mixed>>
     */
    public function modulesByPublicIds(User $user, array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }

        $modules = Module::query()
            ->whereIn('public_id', $publicIds)
            ->with([
                'resources.lessonMaterials.moduleResource',
                'moduleLessonMaterials.moduleResource',
                'course',
                'quizzes' => fn ($query) => $query->withCount('questions'),
                'assignments' => fn ($query) => $query->withCount('questions')->with('lessonMaterials'),
            ])
            ->get()
            ->sortBy(fn ($m) => array_search($m->public_id, $publicIds, true))
            ->values();

        $formatted = $modules->map(fn ($m) => $this->formatModule($m, $user))->values()->all();

        return $this->applySequentialLessonLocksByCourse($formatted, $user);
    }

    /** Whether a curriculum item (core, standalone lesson, or quiz public id) is locked for this learner. */
    public function isCurriculumItemLockedForUser(User $user, Course $course, string $itemKey): bool
    {
        $itemKey = trim($itemKey);
        if ($itemKey === '') {
            return false;
        }

        $level = $this->userLmsAccessLevel($user, $course);
        if ($user->id > 0 && $level === 'none') {
            return true;
        }

        $map = $this->curriculumLockMapForUser($user, $course);
        if ($level === 'replay' && ! array_key_exists($itemKey, $map)) {
            return true;
        }

        return (bool) ($map[$itemKey] ?? false);
    }

    /**
     * @param  string|null  $kind  quiz | assignment | video | document | stream | zoom | live
     */
    public function curriculumAccessDeniedMessage(User $user, Course $course, ?string $kind = null): ?string
    {
        $level = $this->userLmsAccessLevel($user, $course);
        if ($level === 'full') {
            return null;
        }

        if ($level === 'replay') {
            if ($kind === null || $kind === 'video') {
                return null;
            }

            return 'Blended learning enrollments can replay lecture videos only.';
        }

        if ($this->userHasApprovedEnrollmentForCourse($user, $course)) {
            return 'Face to face enrollments cannot access online course materials. Pure online class has full access. Blended learning can replay lecture videos only.';
        }

        if ($this->userHasApprovedProgramEnrollment($user, $course)) {
            return 'Request access to this course from the program page. An administrator must approve it before you can open lessons.';
        }

        return 'Only learners with an approved program enrollment and course access can open these materials.';
    }

    public function userLmsAccessLevel(User $user, Course $course): string
    {
        if ($user->id <= 0) {
            return 'none';
        }

        $role = strtolower((string) ($user->role ?? ''));
        if (in_array($role, ['admin', 'instructor'], true)) {
            return 'full';
        }

        $best = 'none';
        foreach ($this->approvedEnrollmentsForCourse($user, $course) as $enrollment) {
            $tier = $this->enrollmentDigitalAccessTier($enrollment);
            if ($tier === 'full') {
                return 'full';
            }
            if ($tier === 'replay') {
                $best = 'replay';
            }
        }

        return $best;
    }

    public function userCanAccessCourseLessons(User $user, Course $course): bool
    {
        return $this->userLmsAccessLevel($user, $course) !== 'none';
    }

    /**
     * @return \Illuminate\Support\Collection<int, Enrollment>
     */
    protected function approvedEnrollmentsForCourse(User $user, Course $course)
    {
        $course->loadMissing('program');
        if ($course->program_id === null) {
            return collect();
        }

        return Enrollment::query()
            ->with('learningMode')
            ->where('user_id', $user->id)
            ->approved()
            ->where('course_id', $course->id)
            ->get();
    }

    protected function userHasApprovedEnrollmentForCourse(User $user, Course $course): bool
    {
        return $this->approvedEnrollmentsForCourse($user, $course)->isNotEmpty();
    }

    protected function userHasApprovedProgramEnrollment(User $user, Course $course): bool
    {
        if ($user->id <= 0 || $course->program_id === null) {
            return false;
        }

        return Enrollment::query()
            ->where('user_id', $user->id)
            ->where('program_id', $course->program_id)
            ->whereNull('course_id')
            ->approved()
            ->exists();
    }

    protected function enrollmentDigitalAccessTier(Enrollment $e): string
    {
        $e->loadMissing('learningMode');
        if ($e->learningMode instanceof LearningMode) {
            return LearningMode::digitalAccessTier(
                (string) $e->learningMode->public_id,
                (string) ($e->learningMode->name ?? '')
            );
        }

        $formData = is_array($e->form_data) ? $e->form_data : [];
        $labels = is_array($formData['labels'] ?? null) ? $formData['labels'] : [];

        return LearningMode::digitalAccessTier(
            (string) ($formData['learningModeId'] ?? ''),
            (string) ($labels['learningMode'] ?? '')
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function quizzesForModuleFilter(User $user, ?string $modulePublicId): array
    {
        $q = Quiz::query()->with(['course', 'module']);

        if ($modulePublicId) {
            $module = Module::query()->where('public_id', $modulePublicId)->firstOrFail();
            $q->where('module_id', $module->id);
        }

        return $q->withCount('questions')->orderBy('title')->get()
            ->map(fn (Quiz $quiz) => $this->formatQuiz($quiz, $user))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function quizResultsForUser(User $target, ?string $from = null, ?string $to = null): array
    {
        $query = QuizAttempt::query()
            ->with('quiz')
            ->where('user_id', $target->id)
            ->orderByDesc('attempted_on');
        ExportDateRange::constrain($query, $from, $to, 'attempted_on');

        return $query
            ->get()
            ->map(fn (QuizAttempt $a) => $this->formatQuizAttempt($a))
            ->all();
    }

    public function userCanViewAssignmentSummaries(User $user): bool
    {
        return $this->userCanViewInstructorDashboard($user);
    }

    public function userCanViewAssignmentLeaderboard(User $user, Assignment $assignment): bool
    {
        if ($this->userCanViewAssignmentSummaries($user)) {
            return true;
        }

        $course = $assignment->course;
        if ($course === null) {
            return false;
        }

        if ($this->userLmsAccessLevel($user, $course) !== 'none') {
            return true;
        }

        if ($user->id > 0 && $this->userHasApprovedEnrollmentForCourse($user, $course)) {
            return true;
        }

        return $user->id > 0
            && AssignmentAttempt::query()
                ->where('assignment_id', $assignment->id)
                ->where('user_id', $user->id)
                ->exists();
    }

    /** @return array<int, array<string, mixed>> */
    public function assignmentSummariesForStaff(?string $from = null, ?string $to = null): array
    {
        $query = Assignment::query()
            ->with('course')
            ->orderBy('title');
        ExportDateRange::constrain($query, $from, $to);
        $assignments = $query->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        $assignmentIds = $assignments->pluck('id')->all();
        $enrolledByCourseId = [];
        $attemptsByAssignment = [];

        foreach ($assignments as $assignment) {
            $course = $assignment->course;
            if ($course === null) {
                continue;
            }
            $courseId = (int) $course->id;
            if (! array_key_exists($courseId, $enrolledByCourseId)) {
                $enrolledByCourseId[$courseId] = $this->enrolledUserIdsForCourse($course);
            }
        }

        $attemptRows = AssignmentAttempt::query()
            ->whereIn('assignment_id', $assignmentIds)
            ->orderByDesc('score')
            ->orderBy('duration_used_seconds')
            ->orderBy('created_at')
            ->get(['assignment_id', 'user_id', 'score']);

        foreach ($attemptRows as $attempt) {
            $aid = (int) $attempt->assignment_id;
            $uid = (int) $attempt->user_id;
            if (! isset($attemptsByAssignment[$aid][$uid])) {
                $attemptsByAssignment[$aid][$uid] = $attempt;
            }
        }

        return $assignments
            ->map(function (Assignment $assignment) use ($enrolledByCourseId, $attemptsByAssignment) {
                $course = $assignment->course;
                if ($course === null) {
                    return null;
                }

                $courseId = (int) $course->id;
                $enrolledIds = $enrolledByCourseId[$courseId] ?? [];
                $total = count($enrolledIds);
                $passingGrade = $this->assignmentPassingGrade($assignment);
                $bestForAssignment = $attemptsByAssignment[(int) $assignment->id] ?? [];

                $passed = 0;
                $nonPassed = 0;
                foreach ($enrolledIds as $userId) {
                    $attempt = $bestForAssignment[$userId] ?? null;
                    if ($attempt === null) {
                        continue;
                    }
                    if ((int) $attempt->score >= $passingGrade) {
                        $passed++;
                    } else {
                        $nonPassed++;
                    }
                }

                $submitted = $passed + $nonPassed;
                $pending = max(0, $total - $submitted);

                return [
                    'id' => $assignment->public_id,
                    'title' => $assignment->title,
                    'course' => $course->title,
                    'courseId' => $course->public_id,
                    'total' => $total,
                    'passed' => $passed,
                    'nonPassed' => $nonPassed,
                    'pending' => $pending,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function userCanViewQuizSummaries(User $user): bool
    {
        return $this->userCanViewInstructorDashboard($user);
    }

    public function userCanViewQuizLeaderboard(User $user, Quiz $quiz): bool
    {
        if ($this->userCanViewQuizSummaries($user)) {
            return true;
        }

        $course = $quiz->course;
        if ($course === null) {
            return false;
        }

        if ($this->userLmsAccessLevel($user, $course) !== 'none') {
            return true;
        }

        if ($user->id > 0 && $this->userHasApprovedEnrollmentForCourse($user, $course)) {
            return true;
        }

        return $user->id > 0
            && QuizAttempt::query()
                ->where('quiz_id', $quiz->id)
                ->where('user_id', $user->id)
                ->exists();
    }

    /** @return array<int, array<string, mixed>> */
    public function quizSummariesForStaff(?string $from = null, ?string $to = null): array
    {
        $query = Quiz::query()
            ->with('course')
            ->orderBy('title');
        ExportDateRange::constrain($query, $from, $to);
        $quizzes = $query->get();

        if ($quizzes->isEmpty()) {
            return [];
        }

        $quizIds = $quizzes->pluck('id')->all();
        $enrolledByCourseId = [];
        $attemptsByQuiz = [];

        foreach ($quizzes as $quiz) {
            $course = $quiz->course;
            if ($course === null) {
                continue;
            }
            $courseId = (int) $course->id;
            if (! array_key_exists($courseId, $enrolledByCourseId)) {
                $enrolledByCourseId[$courseId] = $this->enrolledUserIdsForCourse($course);
            }
        }

        $attemptRows = QuizAttempt::query()
            ->whereIn('quiz_id', $quizIds)
            ->orderByDesc('score')
            ->orderBy('duration_used_seconds')
            ->orderBy('created_at')
            ->get(['quiz_id', 'user_id', 'score']);

        foreach ($attemptRows as $attempt) {
            $qid = (int) $attempt->quiz_id;
            $uid = (int) $attempt->user_id;
            if (! isset($attemptsByQuiz[$qid][$uid])) {
                $attemptsByQuiz[$qid][$uid] = $attempt;
            }
        }

        return $quizzes
            ->map(function (Quiz $quiz) use ($enrolledByCourseId, $attemptsByQuiz) {
                $course = $quiz->course;
                if ($course === null) {
                    return null;
                }

                $courseId = (int) $course->id;
                $enrolledIds = $enrolledByCourseId[$courseId] ?? [];
                $total = count($enrolledIds);
                $passingGrade = $this->quizPassingGrade($quiz);
                $bestForQuiz = $attemptsByQuiz[(int) $quiz->id] ?? [];

                $passed = 0;
                $nonPassed = 0;
                foreach ($enrolledIds as $userId) {
                    $attempt = $bestForQuiz[$userId] ?? null;
                    if ($attempt === null) {
                        continue;
                    }
                    if ((int) $attempt->score >= $passingGrade) {
                        $passed++;
                    } else {
                        $nonPassed++;
                    }
                }

                $submitted = $passed + $nonPassed;
                $pending = max(0, $total - $submitted);

                return [
                    'id' => $quiz->public_id,
                    'title' => $quiz->title,
                    'course' => $course->title,
                    'courseId' => $course->public_id,
                    'total' => $total,
                    'passed' => $passed,
                    'nonPassed' => $nonPassed,
                    'pending' => $pending,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     quiz: array<string, mixed>,
     *     counts: array<string, int>,
     *     data: array<int, array<string, mixed>>,
     *     meta: array<string, mixed>
     * }
     */
    public function quizStudentProgressPaginated(
        Quiz $quiz,
        string $status,
        int $page,
        int $perPage,
        ?string $search = null
    ): array {
        $course = $quiz->course;
        if ($course === null) {
            abort(404, 'Quiz course not found.');
        }

        $enrolledUserIds = $this->enrolledUserIdsForCourse($course);
        $passingGrade = $this->quizPassingGrade($quiz);

        $usersQuery = User::query()
            ->whereIn('id', $enrolledUserIds)
            ->orderBy('name')
            ->orderBy('id');

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $usersQuery->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        $users = $usersQuery->get(['id', 'public_uid', 'name', 'email']);

        $attemptRows = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->whereIn('user_id', $enrolledUserIds)
            ->orderByDesc('score')
            ->orderBy('duration_used_seconds')
            ->orderBy('created_at')
            ->get(['user_id', 'score', 'attempted_on', 'correct_answers', 'total_questions', 'duration_used_label']);

        $bestByUser = [];
        foreach ($attemptRows as $attempt) {
            $uid = (int) $attempt->user_id;
            if (isset($bestByUser[$uid])) {
                continue;
            }
            $bestByUser[$uid] = $attempt;
        }

        $allRows = [];
        $counts = ['total' => 0, 'passed' => 0, 'nonPassed' => 0, 'pending' => 0];

        foreach ($users as $user) {
            $attempt = $bestByUser[(int) $user->id] ?? null;
            if ($attempt === null) {
                $rowStatus = 'pending';
                $counts['pending']++;
            } elseif ((int) $attempt->score >= $passingGrade) {
                $rowStatus = 'passed';
                $counts['passed']++;
            } else {
                $rowStatus = 'non_passed';
                $counts['nonPassed']++;
            }
            $counts['total']++;

            $allRows[] = [
                'id' => (string) ($user->public_uid ?: 'user-'.$user->id),
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'status' => $rowStatus,
                'score' => $attempt !== null ? (int) $attempt->score : null,
                'attemptedOn' => $attempt?->attempted_on?->format('Y-m-d'),
                'correctAnswers' => $attempt !== null ? (int) $attempt->correct_answers : null,
                'totalQuestions' => $attempt !== null ? (int) $attempt->total_questions : null,
                'durationUsed' => $attempt?->duration_used_label,
            ];
        }

        $statusNorm = in_array($status, ['passed', 'non_passed', 'pending'], true) ? $status : 'passed';
        $filtered = array_values(array_filter($allRows, fn (array $row) => $row['status'] === $statusNorm));
        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($filtered, $offset, $perPage);
        $sliceCount = count($slice);

        return [
            'quiz' => [
                'id' => $quiz->public_id,
                'title' => $quiz->title,
                'course' => $course->title,
                'courseId' => $course->public_id,
                'passingGrade' => $passingGrade,
            ],
            'counts' => $counts,
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => $total === 0 ? 0 : $offset + $sliceCount,
                'status' => $statusNorm,
            ],
        ];
    }

    /**
     * @return array{
     *     quiz: array<string, mixed>,
     *     data: array<int, array<string, mixed>>,
     *     meta: array<string, mixed>
     * }
     */
    public function quizLeaderboardPaginated(
        Quiz $quiz,
        int $page,
        int $perPage,
        ?string $search = null,
        bool $useAliasNames = false,
        ?User $viewer = null
    ): array {
        $course = $quiz->course;
        if ($course === null) {
            abort(404, 'Quiz course not found.');
        }

        $passingGrade = $this->quizPassingGrade($quiz);
        $attemptUserIds = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $enrolledUserIds = $this->leaderboardStudentIdsForCourse($course, $attemptUserIds);
        $aliasByUserId = $useAliasNames
            ? $this->aliasNamesByUserIdForCourse($course, $enrolledUserIds)
            : [];

        $users = User::query()
            ->whereIn('id', $enrolledUserIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'public_uid', 'name', 'email']);

        $attemptRows = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->whereIn('user_id', $enrolledUserIds)
            ->orderByDesc('score')
            ->orderBy('duration_used_seconds')
            ->orderBy('created_at')
            ->get([
                'user_id',
                'score',
                'duration_used_label',
                'duration_used_seconds',
                'correct_answers',
                'total_questions',
                'attempted_on',
            ]);

        $bestByUser = [];
        foreach ($attemptRows as $attempt) {
            $uid = (int) $attempt->user_id;
            if (isset($bestByUser[$uid])) {
                continue;
            }
            $bestByUser[$uid] = $attempt;
        }

        $rows = [];
        foreach ($users as $user) {
            $attempt = $bestByUser[(int) $user->id] ?? null;
            if ($attempt === null) {
                continue;
            }

            $score = (int) $attempt->score;
            $durationSeconds = (int) ($attempt->duration_used_seconds > 0
                ? $attempt->duration_used_seconds
                : $this->parseAssignmentDurationLabelToSeconds($attempt->duration_used_label));
            $aliasName = trim((string) ($aliasByUserId[(int) $user->id] ?? ''));
            $displayName = $useAliasNames
                ? ($aliasName !== '' ? $aliasName : (string) $user->name)
                : (string) $user->name;

            $rows[] = [
                'id' => (string) ($user->public_uid ?: 'user-'.$user->id),
                'name' => $displayName,
                'aliasName' => $aliasName !== '' ? $aliasName : null,
                'email' => $useAliasNames ? null : (string) $user->email,
                'score' => $score,
                'scoreLabel' => "{$score}%",
                'durationUsed' => (string) ($attempt->duration_used_label ?: $this->formatAssignmentDurationLabel($durationSeconds)),
                'durationUsedSeconds' => $durationSeconds,
                'correctAnswers' => (int) $attempt->correct_answers,
                'totalQuestions' => (int) $attempt->total_questions,
                'detailLabel' => "{$attempt->correct_answers} / {$attempt->total_questions} correct",
                'attemptedOn' => $attempt->attempted_on?->format('Y-m-d'),
                'passed' => $score >= $passingGrade,
                '_sortScore' => $score,
                '_sortDuration' => $durationSeconds,
                '_sortName' => strtolower($displayName),
            ];
        }

        usort(
            $rows,
            fn (array $a, array $b) => $b['_sortScore'] <=> $a['_sortScore']
                ?: $a['_sortDuration'] <=> $b['_sortDuration']
                ?: $a['_sortName'] <=> $b['_sortName']
        );

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $needle = strtolower($term);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => str_contains(strtolower((string) $row['name']), $needle)
                    || (! $useAliasNames && str_contains(strtolower((string) ($row['email'] ?? '')), $needle))
            ));
        }

        $rankedRows = [];
        foreach ($rows as $index => $row) {
            unset($row['_sortScore'], $row['_sortDuration'], $row['_sortName']);
            $row['rank'] = $index + 1;
            $rankedRows[] = $row;
        }

        $myRank = null;
        if ($viewer !== null && $viewer->id > 0) {
            $viewerKey = (string) ($viewer->public_uid ?: 'user-'.$viewer->id);
            foreach ($rankedRows as $row) {
                if (($row['id'] ?? '') === $viewerKey) {
                    $myRank = $row;
                    break;
                }
            }
        }

        $total = count($rankedRows);
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($rankedRows, $offset, $perPage);
        $sliceCount = count($slice);

        $payload = [
            'quiz' => [
                'id' => $quiz->public_id,
                'title' => $quiz->title,
                'course' => $course->title,
                'courseId' => $course->public_id,
                'passingGrade' => $passingGrade,
            ],
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => $total === 0 ? 0 : $offset + $sliceCount,
            ],
        ];

        if ($viewer !== null && $viewer->id > 0) {
            $payload['myRank'] = $myRank;
        }

        return $payload;
    }

    /** @return array<int, int> */
    protected function enrolledUserIdsForCourse(Course $course): array
    {
        $enrolledUserIds = Enrollment::query()
            ->approved()
            ->where('course_id', $course->id)
            ->pluck('user_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($enrolledUserIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $enrolledUserIds)
            ->whereRaw('LOWER(TRIM(role)) = ?', ['student'])
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Students enrolled in the course, plus students who already submitted an attempt.
     *
     * @param  array<int, int>  $attemptUserIds
     * @return array<int, int>
     */
    protected function leaderboardStudentIdsForCourse(Course $course, array $attemptUserIds = []): array
    {
        $ids = array_values(array_unique(array_filter(array_merge(
            $this->enrolledUserIdsForCourse($course),
            array_map(static fn ($id) => (int) $id, $attemptUserIds)
        ))));

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->whereRaw('LOWER(TRIM(role)) = ?', ['student'])
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    protected function assignmentPassingGrade(Assignment $assignment): int
    {
        $settings = is_array($assignment->settings_json) ? $assignment->settings_json : [];
        $passingGrade = (int) ($settings['passingGrade'] ?? 70);

        return max(0, min(100, $passingGrade));
    }

    /**
     * @return array{
     *     assignment: array<string, mixed>,
     *     counts: array<string, int>,
     *     data: array<int, array<string, mixed>>,
     *     meta: array<string, mixed>
     * }
     */
    public function assignmentStudentProgressPaginated(
        Assignment $assignment,
        string $status,
        int $page,
        int $perPage,
        ?string $search = null
    ): array {
        $course = $assignment->course;
        if ($course === null) {
            abort(404, 'Assignment course not found.');
        }

        $enrolledUserIds = $this->enrolledUserIdsForCourse($course);
        $passingGrade = $this->assignmentPassingGrade($assignment);

        $usersQuery = User::query()
            ->whereIn('id', $enrolledUserIds)
            ->orderBy('name')
            ->orderBy('id');

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $usersQuery->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        $users = $usersQuery->get(['id', 'public_uid', 'name', 'email']);

        $attemptRows = AssignmentAttempt::query()
            ->where('assignment_id', $assignment->id)
            ->whereIn('user_id', $enrolledUserIds)
            ->orderByDesc('score')
            ->orderBy('duration_used_seconds')
            ->orderBy('created_at')
            ->get(['user_id', 'score', 'attempted_on', 'correct_answers', 'total_questions']);

        $bestByUser = [];
        foreach ($attemptRows as $attempt) {
            $uid = (int) $attempt->user_id;
            if (isset($bestByUser[$uid])) {
                continue;
            }
            $bestByUser[$uid] = $attempt;
        }

        $allRows = [];
        $counts = ['total' => 0, 'passed' => 0, 'nonPassed' => 0, 'pending' => 0];

        foreach ($users as $user) {
            $attempt = $bestByUser[(int) $user->id] ?? null;
            if ($attempt === null) {
                $rowStatus = 'pending';
                $counts['pending']++;
            } elseif ((int) $attempt->score >= $passingGrade) {
                $rowStatus = 'passed';
                $counts['passed']++;
            } else {
                $rowStatus = 'non_passed';
                $counts['nonPassed']++;
            }
            $counts['total']++;

            $allRows[] = [
                'id' => (string) ($user->public_uid ?: 'user-'.$user->id),
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'status' => $rowStatus,
                'score' => $attempt !== null ? (int) $attempt->score : null,
                'attemptedOn' => $attempt?->attempted_on?->format('Y-m-d'),
                'correctAnswers' => $attempt !== null ? (int) $attempt->correct_answers : null,
                'totalQuestions' => $attempt !== null ? (int) $attempt->total_questions : null,
            ];
        }

        $statusNorm = in_array($status, ['passed', 'non_passed', 'pending'], true) ? $status : 'passed';
        $filtered = array_values(array_filter($allRows, fn (array $row) => $row['status'] === $statusNorm));
        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($filtered, $offset, $perPage);
        $sliceCount = count($slice);

        return [
            'assignment' => [
                'id' => $assignment->public_id,
                'title' => $assignment->title,
                'course' => $course->title,
                'courseId' => $course->public_id,
                'passingGrade' => $passingGrade,
            ],
            'counts' => $counts,
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => $total === 0 ? 0 : $offset + $sliceCount,
                'status' => $statusNorm,
            ],
        ];
    }

    /**
     * @return array{
     *     assignment: array<string, mixed>,
     *     data: array<int, array<string, mixed>>,
     *     meta: array<string, mixed>
     * }
     */
    public function assignmentLeaderboardPaginated(
        Assignment $assignment,
        int $page,
        int $perPage,
        ?string $search = null,
        bool $useAliasNames = false,
        ?User $viewer = null
    ): array {
        $course = $assignment->course;
        if ($course === null) {
            abort(404, 'Assignment course not found.');
        }

        $passingGrade = $this->assignmentPassingGrade($assignment);
        $attemptUserIds = AssignmentAttempt::query()
            ->where('assignment_id', $assignment->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $enrolledUserIds = $this->leaderboardStudentIdsForCourse($course, $attemptUserIds);
        $aliasByUserId = $useAliasNames
            ? $this->aliasNamesByUserIdForCourse($course, $enrolledUserIds)
            : [];

        $users = User::query()
            ->whereIn('id', $enrolledUserIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'public_uid', 'name', 'email']);

        $attemptRows = AssignmentAttempt::query()
            ->where('assignment_id', $assignment->id)
            ->whereIn('user_id', $enrolledUserIds)
            ->orderByDesc('score')
            ->orderBy('duration_used_seconds')
            ->orderBy('created_at')
            ->get([
                'user_id',
                'score',
                'duration_used_label',
                'duration_used_seconds',
                'correct_answers',
                'total_questions',
                'attempted_on',
            ]);

        $bestByUser = [];
        foreach ($attemptRows as $attempt) {
            $uid = (int) $attempt->user_id;
            if (isset($bestByUser[$uid])) {
                continue;
            }
            $bestByUser[$uid] = $attempt;
        }

        $rows = [];
        foreach ($users as $user) {
            $attempt = $bestByUser[(int) $user->id] ?? null;
            if ($attempt === null) {
                continue;
            }

            $score = (int) $attempt->score;
            $durationSeconds = (int) ($attempt->duration_used_seconds > 0
                ? $attempt->duration_used_seconds
                : $this->parseAssignmentDurationLabelToSeconds($attempt->duration_used_label));
            $aliasName = trim((string) ($aliasByUserId[(int) $user->id] ?? ''));
            $displayName = $useAliasNames
                ? ($aliasName !== '' ? $aliasName : (string) $user->name)
                : (string) $user->name;

            $rows[] = [
                'id' => (string) ($user->public_uid ?: 'user-'.$user->id),
                'name' => $displayName,
                'aliasName' => $aliasName !== '' ? $aliasName : null,
                'email' => $useAliasNames ? null : (string) $user->email,
                'score' => $score,
                'scoreLabel' => "{$score}%",
                'durationUsed' => (string) ($attempt->duration_used_label ?: $this->formatAssignmentDurationLabel($durationSeconds)),
                'durationUsedSeconds' => $durationSeconds,
                'correctAnswers' => (int) $attempt->correct_answers,
                'totalQuestions' => (int) $attempt->total_questions,
                'detailLabel' => "{$attempt->correct_answers} / {$attempt->total_questions} correct",
                'attemptedOn' => $attempt->attempted_on?->format('Y-m-d'),
                'passed' => $score >= $passingGrade,
                '_sortScore' => $score,
                '_sortDuration' => $durationSeconds,
                '_sortName' => strtolower($displayName),
            ];
        }

        usort(
            $rows,
            fn (array $a, array $b) => $b['_sortScore'] <=> $a['_sortScore']
                ?: $a['_sortDuration'] <=> $b['_sortDuration']
                ?: $a['_sortName'] <=> $b['_sortName']
        );

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $needle = strtolower($term);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => str_contains(strtolower((string) $row['name']), $needle)
                    || (! $useAliasNames && str_contains(strtolower((string) ($row['email'] ?? '')), $needle))
            ));
        }

        $rankedRows = [];
        foreach ($rows as $index => $row) {
            unset($row['_sortScore'], $row['_sortDuration'], $row['_sortName']);
            $row['rank'] = $index + 1;
            $rankedRows[] = $row;
        }

        $myRank = null;
        if ($viewer !== null && $viewer->id > 0) {
            $viewerKey = (string) ($viewer->public_uid ?: 'user-'.$viewer->id);
            foreach ($rankedRows as $row) {
                if (($row['id'] ?? '') === $viewerKey) {
                    $myRank = $row;
                    break;
                }
            }
        }

        $total = count($rankedRows);
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($rankedRows, $offset, $perPage);
        $sliceCount = count($slice);

        $payload = [
            'assignment' => [
                'id' => $assignment->public_id,
                'title' => $assignment->title,
                'course' => $course->title,
                'courseId' => $course->public_id,
                'passingGrade' => $passingGrade,
            ],
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => $total === 0 ? 0 : $offset + $sliceCount,
            ],
        ];

        if ($viewer !== null && $viewer->id > 0) {
            $payload['myRank'] = $myRank;
        }

        return $payload;
    }

    protected function parseAssignmentDurationLabelToSeconds(?string $label): int
    {
        if ($label === null || trim($label) === '') {
            return PHP_INT_MAX;
        }

        if (preg_match('/(\d+)m\s*(\d+)s/i', $label, $matches) === 1) {
            return max(0, ((int) $matches[1] * 60) + (int) $matches[2]);
        }

        return PHP_INT_MAX;
    }

    protected function formatAssignmentDurationLabel(int $seconds): string
    {
        $safe = max(0, $seconds);
        $minutes = intdiv($safe, 60);
        $remaining = $safe % 60;

        return sprintf('%dm %02ds', $minutes, $remaining);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function myAssignmentsPaginatedForUser(
        User $user,
        int $page,
        int $perPage,
        string $status = 'all',
        ?string $search = null
    ): array {
        $courseIds = $this->accessiblePublishedCourseIdsForUser($user);
        if ($courseIds === []) {
            return $this->emptyPaginatedPayload($page, $perPage);
        }

        $assignments = Assignment::query()
            ->whereIn('course_id', $courseIds)
            ->with(['course'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        if ($assignments->isEmpty()) {
            return $this->emptyPaginatedPayload($page, $perPage);
        }

        $assignmentIds = $assignments->pluck('id')->all();
        $attemptRows = AssignmentAttempt::query()
            ->where('user_id', $user->id)
            ->whereIn('assignment_id', $assignmentIds)
            ->orderByDesc('score')
            ->orderBy('duration_used_seconds')
            ->orderByDesc('created_at')
            ->get();

        $bestAttemptByAssignment = [];
        foreach ($attemptRows as $attempt) {
            $aid = (int) $attempt->assignment_id;
            if (! isset($bestAttemptByAssignment[$aid])) {
                $bestAttemptByAssignment[$aid] = $attempt;
            }
        }

        $rows = $assignments
            ->map(fn (Assignment $assignment) => $this->formatMyAssignmentRow(
                $assignment,
                $bestAttemptByAssignment[(int) $assignment->id] ?? null
            ))
            ->values()
            ->all();

        $statusNorm = strtolower(trim($status));
        $statusMap = [
            'passed' => 'Passed',
            'failed' => 'Failed',
            'pending' => 'Pending',
        ];
        if (isset($statusMap[$statusNorm])) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => $row['status'] === $statusMap[$statusNorm]
            ));
        }

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $needle = strtolower($term);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => str_contains(strtolower((string) ($row['title'] ?? '')), $needle)
                    || str_contains(strtolower((string) ($row['course'] ?? '')), $needle)
                    || str_contains(strtolower((string) ($row['teacher'] ?? '')), $needle)
            ));
        }

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($rows, $offset, $perPage);
        $sliceCount = count($slice);

        return [
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => $total === 0 ? 0 : $offset + $sliceCount,
            ],
        ];
    }

    /** @return array<int, int> */
    protected function accessiblePublishedCourseIdsForUser(User $user): array
    {
        if ($user->id <= 0) {
            return [];
        }

        $enrollments = Enrollment::query()
            ->with(['course'])
            ->where('user_id', $user->id)
            ->approved()
            ->whereNotNull('course_id')
            ->get();

        $courseIds = collect();

        foreach ($enrollments as $enrollment) {
            if ($enrollment->course_id && $enrollment->course && $enrollment->course->is_published) {
                $courseIds->push((int) $enrollment->course_id);
            }
        }

        return $courseIds->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatMyAssignmentRow(Assignment $assignment, ?AssignmentAttempt $attempt): array
    {
        $course = $assignment->course;
        $passingGrade = $this->assignmentPassingGrade($assignment);
        $score = $attempt !== null ? (int) $attempt->score : null;
        $correct = $attempt !== null ? (int) $attempt->correct_answers : null;
        $totalQuestions = $attempt !== null ? (int) $attempt->total_questions : null;

        if ($attempt === null) {
            $status = 'Pending';
        } elseif ($score >= $passingGrade) {
            $status = 'Passed';
        } else {
            $status = 'Failed';
        }

        $updatedSource = $attempt?->attempted_on ?? $assignment->updated_at;
        $updatedAtLabel = $updatedSource !== null
            ? $updatedSource->diffForHumans()
            : '—';

        return [
            'id' => $assignment->public_id,
            'title' => $assignment->title,
            'course' => $course?->title ?? '',
            'courseId' => $course?->public_id ?? '',
            'courseSlug' => $course?->slug ?? '',
            'teacher' => trim((string) ($course?->mentor_display_name ?? '')) ?: '—',
            'updatedAt' => $updatedAtLabel,
            'status' => $status,
            'gradeLabel' => $this->assignmentScoreLetterGrade($score),
            'scoreLabel' => $this->assignmentScoreSummaryLabel($score, $correct, $totalQuestions),
            'progressLabel' => $score !== null ? "{$score}%" : '—',
            'score' => $score,
            'passingGrade' => $passingGrade,
        ];
    }

    protected function assignmentScoreLetterGrade(?int $score): string
    {
        if ($score === null) {
            return '—';
        }

        return match (true) {
            $score >= 97 => 'A+',
            $score >= 93 => 'A',
            $score >= 90 => 'A-',
            $score >= 87 => 'B+',
            $score >= 83 => 'B',
            $score >= 80 => 'B-',
            $score >= 77 => 'C+',
            $score >= 73 => 'C',
            $score >= 70 => 'C-',
            $score >= 60 => 'D',
            default => 'F',
        };
    }

    protected function assignmentScoreSummaryLabel(?int $score, ?int $correct, ?int $total): string
    {
        if ($score === null) {
            return '—';
        }

        if ($correct !== null && $total !== null && $total > 0) {
            return "({$correct}/{$total})";
        }

        return "({$score}/100)";
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function myQuizzesPaginatedForUser(
        User $user,
        int $page,
        int $perPage,
        string $status = 'all',
        ?string $search = null
    ): array {
        $courseIds = $this->accessiblePublishedCourseIdsForUser($user);
        if ($courseIds === []) {
            return $this->emptyPaginatedPayload($page, $perPage);
        }

        $quizzes = Quiz::query()
            ->whereIn('course_id', $courseIds)
            ->with(['course'])
            ->withCount('questions')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        if ($quizzes->isEmpty()) {
            return $this->emptyPaginatedPayload($page, $perPage);
        }

        $quizIds = $quizzes->pluck('id')->all();
        $allAttemptRows = QuizAttempt::query()
            ->where('user_id', $user->id)
            ->whereIn('quiz_id', $quizIds)
            ->get();

        $attemptCountByQuiz = [];
        foreach ($allAttemptRows as $attempt) {
            $qid = (int) $attempt->quiz_id;
            $attemptCountByQuiz[$qid] = ($attemptCountByQuiz[$qid] ?? 0) + 1;
        }

        $rankedAttempts = QuizAttempt::query()
            ->where('user_id', $user->id)
            ->whereIn('quiz_id', $quizIds)
            ->orderByDesc('score')
            ->orderBy('duration_used_seconds')
            ->orderByDesc('created_at')
            ->get();

        $bestAttemptByQuiz = [];
        foreach ($rankedAttempts as $attempt) {
            $qid = (int) $attempt->quiz_id;
            if (! isset($bestAttemptByQuiz[$qid])) {
                $bestAttemptByQuiz[$qid] = $attempt;
            }
        }

        $rows = $quizzes
            ->map(function (Quiz $quiz) use ($attemptCountByQuiz, $bestAttemptByQuiz) {
                return $this->formatMyQuizRow(
                    $quiz,
                    $attemptCountByQuiz[(int) $quiz->id] ?? 0,
                    $bestAttemptByQuiz[(int) $quiz->id] ?? null
                );
            })
            ->values()
            ->all();

        $statusNorm = strtolower(trim($status));
        $statusMap = [
            'passed' => 'Passed',
            'failed' => 'Failed',
            'pending' => 'Pending',
        ];
        if (isset($statusMap[$statusNorm])) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => $row['status'] === $statusMap[$statusNorm]
            ));
        }

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $needle = strtolower($term);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => str_contains(strtolower((string) ($row['title'] ?? '')), $needle)
                    || str_contains(strtolower((string) ($row['courseTitle'] ?? '')), $needle)
            ));
        }

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($rows, $offset, $perPage);
        $sliceCount = count($slice);

        return [
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => $total === 0 ? 0 : $offset + $sliceCount,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatMyQuizRow(Quiz $quiz, int $attemptsUsed, ?QuizAttempt $bestAttempt): array
    {
        $course = $quiz->course;
        $passingGrade = $this->quizPassingGrade($quiz);
        $questionCount = $this->resolvedQuizQuestionCount($quiz);
        $bestScore = $bestAttempt !== null ? (int) $bestAttempt->score : null;

        if ($attemptsUsed <= 0) {
            $status = 'Pending';
        } elseif ($bestScore >= $passingGrade) {
            $status = 'Passed';
        } else {
            $status = 'Failed';
        }

        $score = $attemptsUsed > 0 ? $bestScore : null;
        $correct = $bestAttempt !== null ? (int) $bestAttempt->correct_answers : null;
        $totalQuestions = $bestAttempt !== null ? (int) $bestAttempt->total_questions : null;

        return [
            'id' => $quiz->public_id,
            'quizId' => $quiz->public_id,
            'courseId' => $course?->public_id ?? '',
            'courseSlug' => $course?->slug ?? '',
            'courseTitle' => $course?->title ?? '',
            'title' => $quiz->title,
            'attemptsUsed' => $attemptsUsed,
            'attemptsLabel' => "{$attemptsUsed} attempt(s)",
            'questionCount' => $questionCount,
            'questionsLabel' => "{$questionCount} questions",
            'status' => $status,
            'gradeLabel' => $this->assignmentScoreLetterGrade($score),
            'scoreLabel' => $this->assignmentScoreSummaryLabel($score, $correct, $totalQuestions),
            'progressLabel' => $score !== null ? "{$score}%" : '—',
            'bestScore' => $score,
            'passingGrade' => $passingGrade,
        ];
    }

    protected function quizPassingGrade(Quiz $quiz): int
    {
        $settings = is_array($quiz->settings_json) ? $quiz->settings_json : [];
        $authoring = $this->normalizeQuizAuthoringFromRow($quiz, $settings);
        $passingGrade = (int) ($authoring['passingGrade'] ?? 0);

        return max(0, min(100, $passingGrade > 0 ? $passingGrade : 70));
    }

    /**
     * @return array{data: array<int, mixed>, meta: array<string, mixed>}
     */
    protected function emptyPaginatedPayload(int $page, int $perPage): array
    {
        return [
            'data' => [],
            'meta' => [
                'current_page' => max(1, $page),
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
                'from' => 0,
                'to' => 0,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function leaderboardForPeriod(string $period, ?string $from = null, ?string $to = null): array
    {
        if (! in_array($period, LmsMeta::LEADERBOARD_PERIODS, true)) {
            abort(422, 'Invalid leaderboard type.');
        }

        $build = function () use ($period, $from, $to) {
            $query = LeaderboardEntry::query()
                ->where('period', $period)
                ->orderBy('rank_order');
            ExportDateRange::constrain($query, $from, $to, 'updated_at');

            return $query
                ->get()
                ->map(fn ($row) => [
                    'id' => 'rank-'.$row->id,
                    'name' => $row->display_name ?? $row->user?->name ?? 'Learner',
                    'program' => $row->program_code ?? '',
                    'score' => (int) $row->score,
                    'badge' => isset($row->badge_key) ? (LmsMeta::BADGE_LABELS[$row->badge_key] ?? $row->badge_key) : null,
                ])
                ->values()
                ->all();
        };

        if ($from || $to) {
            return $build();
        }

        $cacheKey = 'lms:leaderboard:'.$period;

        return Cache::remember($cacheKey, now()->addSeconds(45), $build);
    }

    public function analyticsForUser(User $user): array
    {
        $cacheKey = 'lms:analytics:'.$user->id;

        return Cache::remember($cacheKey, now()->addSeconds(45), function () use ($user) {
            return $this->buildAnalytics($user);
        });
    }

    public function adminPayload(): array
    {
        return $this->formatAdmin();
    }

    public static function bustUserAnalyticsCache(int $userId): void
    {
        Cache::forget('lms:analytics:'.$userId);
        self::bustInstructorDashboardStatsCache();
    }

    public static function bustInstructorDashboardStatsCache(): void
    {
        Cache::forget('lms:instructor-dashboard-stats');
    }

    public static function bustLeaderboardCache(): void
    {
        foreach (LmsMeta::LEADERBOARD_PERIODS as $period) {
            Cache::forget('lms:leaderboard:'.$period);
        }
    }

    protected function formatActor(User $user): array
    {
        $profile = $user->lmsProfile;
        $student = $user->studentProfile;
        $instructor = $user->instructorProfile;
        $badges = $user->badges->map(fn ($b) => $b->badge_label ?? (LmsMeta::BADGE_LABELS[$b->badge_key] ?? $b->badge_key))->all();
        $isStudent = strtolower((string) $user->role) === 'student';
        $fullName = trim((string) $user->name);
        $nameParts = preg_split('/\s+/', $fullName) ?: [];
        $firstName = $nameParts[0] ?? '';
        $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';
        $publicName = trim((string) ($instructor?->display_name ?? ''));
        if ($publicName === '') {
            $publicName = $fullName;
        }

        return [
            'id' => $user->public_uid,
            'displayName' => $publicName,
            'fullName' => $fullName,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $user->email,
            'role' => $user->role,
            'isStudent' => $isStudent,
            'status' => $user->status ?? 'active',
            'activeProgram' => $profile?->program?->code ?? 'CE',
            'joinedAt' => optional($profile?->joined_at)->format('Y-m-d') ?? $user->created_at->format('Y-m-d'),
            'streak' => (int) ($profile?->streak_days ?? 0),
            'badges' => $badges,
            'watermarkName' => $profile?->watermark_name ?? $publicName,
            'sessionWarning' => (bool) ($profile?->session_warning ?? false),
            'phoneNumber' => $student?->phone_number,
            'birthday' => optional($student?->birthday)->format('Y-m-d'),
            'schoolHeld' => $student?->school_held,
            'aliasName' => $student?->alias_name,
            'position' => $instructor?->position,
            'bio' => $instructor?->bio,
            'facebook' => $instructor?->facebook,
            'linkedin' => $instructor?->linkedin,
            'twitter' => $instructor?->twitter,
            'instagram' => $instructor?->instagram,
            'profilePath' => $instructor?->profile_path,
            'profileUrl' => $this->resolveMediaDeliveryUrl($instructor?->profile_path),
            'coverPath' => $instructor?->cover_path,
            'coverUrl' => $this->resolveMediaDeliveryUrl($instructor?->cover_path),
        ];
    }

    protected function formatProgram(Program $p): array
    {
        return [
            'id' => $p->public_id,
            'code' => $p->code,
            'slug' => $p->slug,
            'title' => $p->title,
            'description' => $p->description,
            'enrollmentFee' => $p->enrollment_fee !== null ? (float) $p->enrollment_fee : null,
            'status' => $p->status ?? 'active',
            'bannerPath' => $p->banner_path,
            'bannerUrl' => $this->resolveMediaDeliveryUrl($p->banner_path),
            'updatedAt' => $p->updated_at?->toIso8601String(),
        ];
    }

    protected function resolveMediaDeliveryUrl(?string $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $raw)) {
            $path = parse_url($raw, PHP_URL_PATH);
            if (! is_string($path) || trim($path) === '') {
                return $raw;
            }
            if (str_starts_with($path, '/api/media/')) {
                return $path;
            }
            $raw = $path;
        }

        if (str_starts_with($raw, '/api/media/')) {
            return $raw;
        }

        $normalized = str_replace('\\', '/', $raw);
        if (str_starts_with($normalized, '/storage/')) {
            $normalized = substr($normalized, 9);
        }
        $normalized = ltrim($normalized, '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            return null;
        }

        $disk = Storage::disk('public')->exists($normalized)
            ? 'public'
            : (Storage::disk('local')->exists($normalized) ? 'local' : null);
        if ($disk === null) {
            return null;
        }

        $media = CmsMedia::query()
            ->where('disk', $disk)
            ->where('path', $normalized)
            ->first();

        if ($media === null) {
            $mime = null;
            $size = 0;
            try {
                $mime = Storage::disk($disk)->mimeType($normalized) ?: null;
                $size = (int) (Storage::disk($disk)->size($normalized) ?: 0);
            } catch (\Throwable) {
                $mime = null;
                $size = 0;
            }

            $media = CmsMedia::query()->create([
                'public_id' => 'media-'.Str::lower(Str::ulid()),
                'uploaded_by' => null,
                'disk' => $disk,
                'path' => $normalized,
                'url' => '/storage/'.$normalized,
                'filename' => basename($normalized),
                'original_name' => basename($normalized),
                'mime' => $mime,
                'size_bytes' => $size,
                'alt' => null,
            ]);
        }

        return '/api/media/'.$media->public_id.'/file';
    }

    /**
     * Modules used for learner-facing counts (prefer visible-only; fallback to all if none visible).
     *
     * @return Collection<int, Module>
     */
    protected function modulesForCourseStats(Course $course): Collection
    {
        $visible = $course->modules->filter(fn (Module $m) => (bool) $m->is_visible)->values();

        return $visible->isNotEmpty() ? $visible : $course->modules;
    }

    /**
     * Count learner-visible video lessons (module core + standalone), matching curriculum typing.
     *
     * @param  Collection<int, Module>  $modules
     */
    protected function countVideoLessonsForModules(Collection $modules): int
    {
        $count = 0;

        foreach ($modules as $module) {
            if (! $module->relationLoaded('resources')) {
                $module->load('resources');
            }

            $resources = $module->resources;
            $coreResources = $resources
                ->filter(fn (ModuleResource $resource) => ! (bool) $resource->is_standalone_lesson)
                ->pluck('format')
                ->all();

            if ($module->streaming_only || in_array('Video', $coreResources, true)) {
                $count++;
            }

            foreach ($resources->where('is_standalone_lesson', true) as $standalone) {
                $kind = strtolower((string) ($standalone->lesson_kind ?? ''));
                if (in_array($kind, ['video', 'stream'], true) || $standalone->format === 'Video') {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Mean of learner progress_percent (0–100) across the given modules.
     */
    protected function averageModuleProgressPercent(Collection $modulesStat, User $user): int
    {
        $n = $modulesStat->count();
        if ($n === 0) {
            return 0;
        }

        $moduleIds = $modulesStat->pluck('id');
        $byModule = UserModuleProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('module_id', $moduleIds)
            ->get()
            ->keyBy('module_id');

        $sum = 0;
        foreach ($modulesStat as $m) {
            $sum += (int) ($byModule->get($m->id)?->progress_percent ?? 0);
        }

        return (int) round($sum / $n);
    }

    /**
     * Average score across the learner's recorded attempts for this course's visible quizzes.
     * Returns null when no attempts exist.
     */
    protected function averageQuizScorePercent(Course $course, User $user, Collection $modulesStat): ?int
    {
        $visibleModuleDbIds = $modulesStat->pluck('id');
        $query = Quiz::query()->where('course_id', $course->id);

        if ($visibleModuleDbIds->isNotEmpty()) {
            $query->where(function ($q) use ($visibleModuleDbIds) {
                $q->whereIn('module_id', $visibleModuleDbIds)->orWhereNull('module_id');
            });
        }

        $quizIds = $query->pluck('id');

        if ($quizIds->isEmpty()) {
            return null;
        }

        $avg = QuizAttempt::query()
            ->where('user_id', $user->id)
            ->whereIn('quiz_id', $quizIds)
            ->avg('score');

        return $avg === null ? null : (int) round((float) $avg);
    }

    /**
     * @param  array<string, mixed>|null  $raw  courses.marketing_json
     * @return array{description: array<int, string>, paragraphs: array<int, string>, faq: array<int, array{question: string, answer: string}>, notices: array<int, string>, noticeHeading: string|null, bannerImageUrl: string|null, bannerImageMediaId: string|null, lockLessonsInOrder: bool}
     */
    protected function formatMarketingPayload(?array $raw): array
    {
        $j = is_array($raw) ? $raw : [];
        $description = $this->normalizeStringListField($j['description'] ?? $j['paragraphs'] ?? []);

        // Prefer explicit `bannerImageUrl` (null = cleared); legacy `heroImageUrl` only when key absent.
        $banner = array_key_exists('bannerImageUrl', $j)
            ? $j['bannerImageUrl']
            : ($j['heroImageUrl'] ?? null);
        $bannerTrimmed = null;
        if (is_string($banner) && trim($banner) !== '') {
            $bannerTrimmed = trim($banner);
        }
        $bannerMediaId = isset($j['bannerImageMediaId']) && is_string($j['bannerImageMediaId']) && trim($j['bannerImageMediaId']) !== ''
            ? trim($j['bannerImageMediaId'])
            : null;

        return [
            'description' => $description,
            'paragraphs' => $description,
            'coInstructors' => $this->normalizeStringListField($j['coInstructors'] ?? []),
            'faq' => $this->normalizeFaqListField($j['faq'] ?? $j['faqs'] ?? []),
            'notices' => $this->normalizeStringListField($j['notices'] ?? []),
            'noticeHeading' => isset($j['noticeHeading']) && is_string($j['noticeHeading']) && trim($j['noticeHeading']) !== ''
                ? trim($j['noticeHeading'])
                : null,
            'bannerImageUrl' => $bannerTrimmed,
            'bannerImageMediaId' => $bannerMediaId,
            'lockLessonsInOrder' => (bool) ($j['lockLessonsInOrder'] ?? false),
        ];
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    protected function normalizeStringListField($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            $s = is_string($item) ? trim($item) : (is_scalar($item) ? trim((string) $item) : '');
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $value
     * @return array<int, array{question: string, answer: string}>
     */
    protected function normalizeFaqListField($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $q = isset($row['question']) ? trim((string) $row['question']) : '';
            $a = isset($row['answer']) ? trim((string) $row['answer']) : '';

            if ($q === '' && $a === '') {
                continue;
            }

            $out[] = ['question' => $q, 'answer' => $a];
        }

        return $out;
    }

    /**
     * Split a course-level mentor display string into unique names (comma / slash / "and" lists).
     *
     * @return array<int, string>
     */
    protected function splitMentorDisplayNames(?string $raw): array
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return [];
        }

        $parts = preg_split('/\s*[,;&\/|]\s*|\s+\band\s+/i', $s) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $t = trim((string) $part);
            if ($t === '') {
                continue;
            }
            if (! in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        return $out !== [] ? $out : [$s];
    }

    /**
     * @param  \Illuminate\Support\Collection|array  $completedModuleIds
     */
    public function formatCourse(Course $c, User $user, $completedModuleIds): array
    {
        $modulesStat = $this->modulesForCourseStats($c);
        $moduleCount = $modulesStat->count();
        $completed = $modulesStat->whereIn('id', $completedModuleIds)->count();

        $averageModuleProgressPercent = $moduleCount === 0
            ? 0
            : $this->averageModuleProgressPercent($modulesStat, $user);

        $averageQuizScorePercent = $this->averageQuizScorePercent($c, $user, $modulesStat);

        $marketing = $this->formatMarketingPayload($c->marketing_json);
        $mentors = ! empty($marketing['coInstructors'])
            ? $marketing['coInstructors']
            : $this->splitMentorDisplayNames($c->mentor_display_name);

        return [
            'id' => $c->public_id,
            'slug' => $c->slug,
            'title' => $c->title,
            'programId' => $c->program?->public_id ?? '',
            'programSlug' => $c->program?->slug,
            'programTitle' => $c->program?->title ?? '',
            'mentor' => $c->mentor_display_name,
            'mentors' => $mentors,
            'description' => $c->description,
            'level' => $c->level,
            'totalModules' => $moduleCount ?: (int) $c->total_modules,
            'completedModules' => $completed,
            'averageModuleProgressPercent' => $averageModuleProgressPercent,
            'averageQuizScorePercent' => $averageQuizScorePercent,
            'marketing' => $marketing,
            'hours' => (int) $c->hours,
            'courseFee' => (int) ($c->course_fee ?? 0),
            'learners' => (int) $c->learners_count,
            'nextModuleId' => $c->nextModule?->public_id,
            'tags' => $c->tags->pluck('name')->all(),
            'subjects' => $c->subjects->pluck('name')->all(),
            'updatedAt' => $c->updated_at?->toIso8601String(),
            'status' => $c->is_published ? 'published' : 'draft',
            'isPublished' => (bool) $c->is_published,
            'canAccessLessons' => $this->userCanAccessCourseLessons($user, $c),
            'lmsAccess' => $this->userLmsAccessLevel($user, $c),
            'averageRating' => $c->average_rating !== null ? round((float) $c->average_rating, 1) : null,
            ...($c->video_hours_label ? ['videoHoursLabel' => $c->video_hours_label] : []),
            ...($c->preview_completed ? ['previewCompleted' => true] : []),
        ];
    }

    public function modulePayloadForUser(Module $m, User $user): array
    {
        $row = $this->formatModule($m, $user);
        $m->loadMissing('course');
        $locked = $this->applySequentialLessonLocksToModules(
            [$row],
            $this->shouldApplyLessonLocksForUser($user, $m->course)
        );

        return $locked[0] ?? $row;
    }

    protected function courseLockLessonsInOrder(Course $course): bool
    {
        $marketing = $this->formatMarketingPayload($course->marketing_json);

        return (bool) ($marketing['lockLessonsInOrder'] ?? false);
    }

    protected function shouldApplyLessonLocksForUser(User $user, Course $course): bool
    {
        if ($user->id <= 0 || strtolower((string) ($user->role ?? '')) === 'guest') {
            return false;
        }

        return $this->courseLockLessonsInOrder($course);
    }

    /**
     * @param  array<int, array<string, mixed>>  $modules
     * @return array<string, bool>  item public id (or `{moduleId}-core`) => locked
     */
    public function curriculumLockMapForUser(User $user, Course $course): array
    {
        $cacheKey = $course->id.':'.$user->id;
        if (isset($this->curriculumLockMapCache[$cacheKey])) {
            return $this->curriculumLockMapCache[$cacheKey];
        }

        $course->loadMissing([
            'modules.resources.lessonMaterials.moduleResource',
            'modules.moduleLessonMaterials.moduleResource',
            'modules.course',
            'modules.quizzes' => fn ($query) => $query->withCount('questions'),
            'modules.assignments' => fn ($query) => $query->withCount('questions')->with('lessonMaterials'),
        ]);

        $formatted = $course->modules
            ->sortBy(fn ($m) => sprintf('%05d', $m->sort_order))
            ->values()
            ->map(fn ($m) => $this->formatModule($m, $user))
            ->all();

        $lockedModules = $this->lockModulesForAccessLevel($formatted, $user, $course);

        $map = [];
        foreach ($lockedModules as $mod) {
            $moduleId = (string) ($mod['id'] ?? '');
            if ($moduleId !== '') {
                $map[$moduleId.'-core'] = (bool) ($mod['coreLocked'] ?? false);
            }
            foreach ($mod['standaloneLessons'] ?? [] as $lesson) {
                if (isset($lesson['id'])) {
                    $map[(string) $lesson['id']] = (bool) ($lesson['locked'] ?? false);
                }
            }
            foreach ($mod['quizzes'] ?? [] as $quiz) {
                if (isset($quiz['id'])) {
                    $map[(string) $quiz['id']] = (bool) ($quiz['locked'] ?? false);
                }
            }
            foreach ($mod['assignments'] ?? [] as $assignment) {
                if (isset($assignment['id'])) {
                    $map[(string) $assignment['id']] = (bool) ($assignment['locked'] ?? false);
                }
            }
        }

        return $this->curriculumLockMapCache[$cacheKey] = $map;
    }

    /**
     * Apply per-learner lock flags on visible modules in global curriculum order.
     *
     * @param  array<int, array<string, mixed>>  $modules
     * @return array<int, array<string, mixed>>
     */
    protected function applySequentialLessonLocksToModules(array $modules, bool $lockLessonsInOrder): array
    {
        if ($modules === []) {
            return [];
        }

        if (! $lockLessonsInOrder) {
            return array_map(fn (array $mod) => $this->applyLockFieldsToModuleRow($mod), $modules);
        }

        $prevChainComplete = true;
        $out = [];

        foreach ($modules as $mod) {
            if (($mod['visible'] ?? true) === false) {
                $out[] = $this->applyLockFieldsToModuleRow($mod);

                continue;
            }

            $moduleCompleted = ((int) ($mod['progress'] ?? 0)) >= 100;
            $coreCompleted = $moduleCompleted || (bool) ($mod['coreCompleted'] ?? false);
            $mod['coreLocked'] = ! $prevChainComplete;
            $prevChainComplete = $prevChainComplete && $coreCompleted;

            $standalone = is_array($mod['standaloneLessons'] ?? null) ? $mod['standaloneLessons'] : [];
            usort(
                $standalone,
                fn ($a, $b) => ((int) ($a['sortOrder'] ?? PHP_INT_MAX)) <=> ((int) ($b['sortOrder'] ?? PHP_INT_MAX))
            );
            $standaloneOut = [];
            foreach ($standalone as $row) {
                $completed = $moduleCompleted || (bool) ($row['completed'] ?? false);
                $row['locked'] = ! $prevChainComplete;
                $standaloneOut[] = $row;
                $prevChainComplete = $prevChainComplete && $completed;
            }
            $mod['standaloneLessons'] = $standaloneOut;

            $quizzes = is_array($mod['quizzes'] ?? null) ? $mod['quizzes'] : [];
            usort(
                $quizzes,
                fn ($a, $b) => ((int) ($a['sortOrder'] ?? PHP_INT_MAX)) <=> ((int) ($b['sortOrder'] ?? PHP_INT_MAX))
            );
            $quizzesOut = [];
            foreach ($quizzes as $quiz) {
                $attemptsUsed = (int) ($quiz['attemptsUsed'] ?? 0);
                $completed = (bool) ($quiz['completed'] ?? false) || $attemptsUsed > 0;
                $quiz['locked'] = ! $prevChainComplete;
                $quizzesOut[] = $quiz;
                $prevChainComplete = $prevChainComplete && $completed;
            }
            $mod['quizzes'] = $quizzesOut;

            $out[] = $mod;
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $modules
     * @return array<int, array<string, mixed>>
     */
    protected function applySequentialLessonLocksByCourse(array $modules, User $user): array
    {
        if ($modules === []) {
            return [];
        }

        $byCourse = [];
        foreach ($modules as $index => $mod) {
            $courseId = (string) ($mod['courseId'] ?? '');
            $byCourse[$courseId][] = $index;
        }

        $out = $modules;
        foreach ($byCourse as $coursePublicId => $indices) {
            $course = Course::query()->where('public_id', $coursePublicId)->first();
            $subset = array_map(fn (int $i) => $modules[$i], $indices);
            $locked = $course !== null
                ? $this->lockModulesForAccessLevel($subset, $user, $course)
                : $this->applySequentialLessonLocksToModules($subset, false);
            foreach ($indices as $pos => $originalIndex) {
                $out[$originalIndex] = $locked[$pos] ?? $modules[$originalIndex];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $mod
     * @return array<string, mixed>
     */
    /**
     * Lock every curriculum item when the learner is signed in but not approved on this course.
     *
     * @param  array<int, array<string, mixed>>  $modules
     * @return array<int, array<string, mixed>>
     */
    protected function applyEnrollmentLocksToModules(array $modules): array
    {
        return array_map(function (array $mod) {
            if (($mod['visible'] ?? true) === false) {
                return $mod;
            }

            $mod['coreLocked'] = true;

            if (is_array($mod['standaloneLessons'] ?? null)) {
                $mod['standaloneLessons'] = array_map(
                    fn (array $row) => array_merge($row, ['locked' => true]),
                    $mod['standaloneLessons']
                );
            }

            if (is_array($mod['quizzes'] ?? null)) {
                $mod['quizzes'] = array_map(
                    fn (array $row) => array_merge($row, ['locked' => true]),
                    $mod['quizzes']
                );
            }

            if (is_array($mod['assignments'] ?? null)) {
                $mod['assignments'] = array_map(
                    fn (array $row) => array_merge($row, ['locked' => true]),
                    $mod['assignments']
                );
            }

            return $mod;
        }, $modules);
    }

    /**
     * @param  array<int, array<string, mixed>>  $modules
     * @return array<int, array<string, mixed>>
     */
    protected function lockModulesForAccessLevel(array $modules, User $user, Course $course): array
    {
        $level = $this->userLmsAccessLevel($user, $course);
        if ($user->id > 0 && $level === 'none') {
            return $this->applyEnrollmentLocksToModules($modules);
        }

        $sequential = $this->applySequentialLessonLocksToModules(
            $modules,
            $level === 'full' && $this->shouldApplyLessonLocksForUser($user, $course)
        );

        if ($level === 'replay') {
            return $this->applyReplayOnlyLocksToModules($sequential);
        }

        return $sequential;
    }

    /**
     * @param  array<string, mixed>  $mod
     */
    protected function moduleCoreKind(array $mod): string
    {
        if (! empty($mod['streamingOnly'])) {
            return 'video';
        }

        $core = is_array($mod['coreResources'] ?? null)
            ? $mod['coreResources']
            : (is_array($mod['resources'] ?? null) ? $mod['resources'] : []);
        foreach ($core as $format) {
            if (strcasecmp((string) $format, 'Video') === 0) {
                return 'video';
            }
        }

        $step = strtolower((string) ($mod['type'] ?? ''));
        if (str_contains($step, 'practice') || str_contains($step, 'coach') || str_contains($step, 'final')) {
            return 'quiz';
        }

        return 'document';
    }

    /**
     * Blended learning: recorded lecture videos stay open; everything else is locked.
     *
     * @param  array<int, array<string, mixed>>  $modules
     * @return array<int, array<string, mixed>>
     */
    protected function applyReplayOnlyLocksToModules(array $modules): array
    {
        return array_map(function (array $mod) {
            if (($mod['visible'] ?? true) === false) {
                return $mod;
            }

            $mod['coreLocked'] = $this->moduleCoreKind($mod) !== 'video';

            if (is_array($mod['standaloneLessons'] ?? null)) {
                $mod['standaloneLessons'] = array_map(
                    function (array $row) {
                        $kind = strtolower((string) ($row['kind'] ?? ''));
                        $row['locked'] = $kind !== 'video';

                        return $row;
                    },
                    $mod['standaloneLessons']
                );
            }

            if (is_array($mod['quizzes'] ?? null)) {
                $mod['quizzes'] = array_map(
                    fn (array $row) => array_merge($row, ['locked' => true]),
                    $mod['quizzes']
                );
            }

            if (is_array($mod['assignments'] ?? null)) {
                $mod['assignments'] = array_map(
                    fn (array $row) => array_merge($row, ['locked' => true]),
                    $mod['assignments']
                );
            }

            return $mod;
        }, $modules);
    }

    protected function applyLockFieldsToModuleRow(array $mod): array
    {
        $mod['coreLocked'] = false;
        if (is_array($mod['standaloneLessons'] ?? null)) {
            $mod['standaloneLessons'] = array_map(function (array $row) {
                $row['locked'] = false;

                return $row;
            }, $mod['standaloneLessons']);
        }
        if (is_array($mod['quizzes'] ?? null)) {
            $mod['quizzes'] = array_map(function (array $row) {
                $row['locked'] = false;

                return $row;
            }, $mod['quizzes']);
        }

        return $mod;
    }

    protected function formatModule($m, User $user): array
    {
        $progress = UserModuleProgress::query()
            ->where('user_id', $user->id)
            ->where('module_id', $m->id)
            ->first();
        $coreLessonKey = $m->public_id.'-core';
        $standaloneLessonKeys = $m->resources
            ->filter(fn (ModuleResource $r) => (bool) $r->is_standalone_lesson)
            ->pluck('public_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
        $lessonKeys = array_values(array_unique(array_merge([$coreLessonKey], $standaloneLessonKeys)));
        $completedSet = [];
        if ($user->id > 0 && $lessonKeys !== []) {
            $completedLessonKeys = UserLessonProgress::query()
                ->where('user_id', $user->id)
                ->where('course_id', $m->course_id)
                ->whereIn('lesson_key', $lessonKeys)
                ->pluck('lesson_key')
                ->map(fn ($k) => (string) $k)
                ->all();
            $completedSet = array_fill_keys($completedLessonKeys, true);
        }

        $hiddenQuizImageIds = $this->quizQuestionImageMaterialIds($m);

        return [
            'id' => $m->public_id,
            'courseId' => $m->course->public_id,
            'title' => $m->title,
            'subject' => $m->subject,
            'topic' => $m->topic,
            'subtopic' => $m->subtopic,
            'type' => $m->learning_flow_step,
            'duration' => $m->duration_label,
            'lastPosition' => $progress?->last_position_label ?? '00:00',
            'progress' => (int) ($progress?->progress_percent ?? 0),
            'visible' => $m->is_visible,
            'streamingOnly' => $m->streaming_only,
            'updatedAt' => $m->updated_at?->toIso8601String(),
            'resources' => $m->resources->pluck('format')->all(),
            /** Non-standalone resource formats only — used to type the module core lesson. */
            'coreResources' => $m->resources
                ->filter(fn (ModuleResource $r) => ! (bool) $r->is_standalone_lesson)
                ->pluck('format')
                ->all(),
            'resourceRows' => $m->resources
                ->map(fn (ModuleResource $r) => [
                    'id' => $r->public_id,
                    'format' => $r->format,
                    'isStandalone' => (bool) $r->is_standalone_lesson,
                ])
                ->values()
                ->all(),
            'standaloneLessons' => $m->resources
                ->filter(fn (ModuleResource $r) => (bool) $r->is_standalone_lesson)
                ->sortBy(fn (ModuleResource $r) => sprintf('%05d-%09d', (int) $r->sort_order, $r->id))
                ->values()
                ->map(fn (ModuleResource $r) => [
                    'id' => $r->public_id,
                    'title' => $r->title !== null && trim((string) $r->title) !== '' ? trim((string) $r->title) : 'Lesson',
                    'kind' => $r->lesson_kind,
                    /** @deprecated Use bodyHtml — kept synced as plain excerpt for storefronts */
                    'summary' => $r->summary,
                    'excerptHtml' => $r->excerpt_html,
                    'bodyHtml' => filled($r->body_html ?? null) ? $r->body_html : ($r->summary ?? ''),
                    'lessonMeta' => LessonMetaSupport::sanitize($r->lesson_meta_json ?? null),
                    'sortOrder' => (int) $r->sort_order,
                    'completed' => isset($completedSet[(string) $r->public_id]),
                    'lessonMaterials' => $r->lessonMaterials
                        ->filter(fn (LessonMaterial $f) => $this->isListedLessonMaterial($f, $hiddenQuizImageIds))
                        ->map(fn (LessonMaterial $f) => $this->formatLessonMaterial($f, $user))
                        ->values()
                        ->all(),
                    'updatedAt' => $r->updated_at?->toIso8601String(),
                ])
                ->all(),
            'summary' => $m->summary,
            'excerptHtml' => $m->excerpt_html,
            'bodyHtml' => filled($m->body_html ?? null) ? $m->body_html : ($m->summary ?? ''),
            'lessonMeta' => LessonMetaSupport::sanitize($m->lesson_meta_json ?? null),
            'coreCompleted' => isset($completedSet[$coreLessonKey]),
            'lessonMaterials' => $m->moduleLessonMaterials
                ->filter(fn (LessonMaterial $f) => $this->isListedLessonMaterial($f, $hiddenQuizImageIds))
                ->map(fn (LessonMaterial $f) => $this->formatLessonMaterial($f, $user))
                ->values()
                ->all(),
            'quizQuestionImageMaterialIds' => $hiddenQuizImageIds,
            'quizzes' => $m->quizzes
                ->map(function (Quiz $q) use ($user) {
                    // Full authoring shape (matches PATCH payload / instructor Settings tab) so refetched modules
                    // keep description, toggles, duration, etc. — not only id/title/questionCount.
                    $row = $this->formatQuiz($q, $user);
                    $row['completed'] = ((int) ($row['attemptsUsed'] ?? 0)) > 0;

                    return $row;
                })
                ->values()
                ->all(),
            'assignments' => $m->assignments
                ->map(fn (Assignment $a) => $this->formatAssignment($a, $user))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function quizQuestionImageMaterialIds($m): array
    {
        $quizIds = $m->relationLoaded('quizzes')
            ? $m->quizzes->pluck('id')
            : Quiz::query()->where('module_id', $m->id)->pluck('id');

        if ($quizIds->isEmpty()) {
            return [];
        }

        $ids = [];
        Question::query()
            ->whereIn('quiz_id', $quizIds)
            ->get(['meta_json'])
            ->each(function (Question $q) use (&$ids) {
                $meta = is_array($q->meta_json) ? $q->meta_json : [];
                foreach (['problemImageMaterialPublicId', 'diagramMaterialPublicId', 'solutionImageMaterialPublicId'] as $key) {
                    $id = isset($meta[$key]) ? trim((string) $meta[$key]) : '';
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            });

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, string>  $hiddenQuizImageIds
     */
    protected function isListedLessonMaterial(LessonMaterial $f, array $hiddenQuizImageIds): bool
    {
        if ($f->isQuizQuestionImage()) {
            return false;
        }

        return ! in_array($f->public_id, $hiddenQuizImageIds, true);
    }

    public function formatLessonMaterial(LessonMaterial $f, User $user): array
    {
        $mime = strtolower((string) ($f->mime ?? ''));
        $isVideo = str_starts_with($mime, 'video/');
        $isGuest = $user->id <= 0 || strtolower((string) ($user->role ?? '')) === 'guest';

        if ($isVideo) {
            return [
                'id' => $f->public_id,
                'name' => $f->original_name,
                'mime' => $f->mime,
                'sizeBytes' => (int) $f->size_bytes,
                'moduleResourceId' => $f->moduleResource?->public_id,
                'usage' => strtolower(trim((string) ($f->usage ?? 'lesson'))) ?: 'lesson',
                'fileUrl' => null,
                'inlineFileUrl' => null,
                'downloadable' => false,
            ];
        }

        $publicPath = $this->ensurePublicLessonMaterialPath($f->storage_path);
        $fileUrl = $publicPath !== null ? '/storage/'.ltrim($publicPath, '/') : null;

        return [
            'id' => $f->public_id,
            'name' => $f->original_name,
            'mime' => $f->mime,
            'sizeBytes' => (int) $f->size_bytes,
            'moduleResourceId' => $f->moduleResource?->public_id,
            'usage' => strtolower(trim((string) ($f->usage ?? 'lesson'))) ?: 'lesson',
            'fileUrl' => $isGuest ? null : $fileUrl,
            'inlineFileUrl' => $isGuest ? null : $fileUrl,
            'downloadable' => ! $isGuest,
        ];
    }

    protected function ensurePublicLessonMaterialPath(?string $rawPath): ?string
    {
        $storagePath = ltrim((string) $rawPath, '/');
        if ($storagePath === '') {
            return null;
        }

        if (Storage::disk('public')->exists($storagePath)) {
            return $storagePath;
        }

        // Backward compatibility for files uploaded before public-disk migration.
        if (! Storage::disk('local')->exists($storagePath)) {
            return null;
        }

        try {
            $source = Storage::disk('local')->readStream($storagePath);
            if (is_resource($source)) {
                Storage::disk('public')->writeStream($storagePath, $source);
                fclose($source);
            } else {
                Storage::disk('public')->put($storagePath, Storage::disk('local')->get($storagePath));
            }
        } catch (\Throwable) {
            return null;
        }

        return Storage::disk('public')->exists($storagePath) ? $storagePath : null;
    }

    public function authoringQuizPayload(Quiz $quiz, User $user): array
    {
        $quiz->loadMissing(['course', 'module']);
        $quiz->loadCount('questions');

        return $this->formatQuiz($quiz, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatQuizQuestion(Question $q, User $user): array
    {
        $meta = is_array($q->meta_json) ? $q->meta_json : [];

        $problemImageMaterialPublicId = $this->quizQuestionMaterialIdFromMeta(
            $meta,
            'problemImageMaterialPublicId',
            'diagramMaterialPublicId'
        );
        $solutionImageMaterialPublicId = $this->quizQuestionMaterialIdFromMeta(
            $meta,
            'solutionImageMaterialPublicId'
        );

        $problemImage = $this->quizQuestionImagePayload($problemImageMaterialPublicId, $user);
        $solutionImage = $this->quizQuestionImagePayload($solutionImageMaterialPublicId, $user);

        $itemNumber = isset($meta['itemNumber']) ? (int) $meta['itemNumber'] : 0;
        $problemImageName = $itemNumber > 0
            ? 'Item '.$itemNumber
            : $problemImage['name'];
        $problemImageMime = $problemImage['mime'];
        $originalName = strtolower((string) ($problemImage['name'] ?? ''));
        if (! str_contains(strtolower((string) ($problemImageMime ?? '')), 'pdf') && str_ends_with($originalName, '.pdf')) {
            $problemImageMime = 'application/pdf';
        }

        $questionType = (string) ($q->question_type ?? 'single_choice');
        if ($questionType !== 'simulation_diagram') {
            $questionType = 'single_choice';
        }

        return [
            'id' => (string) $q->id,
            'prompt' => $q->prompt,
            'questionType' => $questionType,
            'required' => (bool) ($meta['required'] ?? false),
            'problemImageMaterialPublicId' => $problemImageMaterialPublicId,
            'problemImageUrl' => $problemImage['url'],
            'problemImageName' => $problemImageName,
            'problemImageMime' => $problemImageMime,
            'itemNumber' => $itemNumber > 0 ? $itemNumber : null,
            'solutionImageMaterialPublicId' => $solutionImageMaterialPublicId,
            'solutionImageUrl' => $solutionImage['url'],
            'solutionImageName' => $solutionImage['name'],
            'solutionImageMime' => $solutionImage['mime'],
            // Legacy aliases (problem image only)
            'diagramMaterialPublicId' => $problemImageMaterialPublicId,
            'diagramUrl' => $problemImage['url'],
            'diagramName' => $problemImage['name'],
            'options' => $q->relationLoaded('options')
                ? $q->options->map(function (QuestionOption $opt) {
                    return [
                        'id' => (string) $opt->id,
                        'label' => $opt->label,
                        'isCorrect' => (bool) $opt->is_correct,
                    ];
                })->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function quizQuestionMaterialIdFromMeta(array $meta, string $primaryKey, ?string $legacyKey = null): ?string
    {
        $id = isset($meta[$primaryKey]) ? trim((string) $meta[$primaryKey]) : '';
        if ($id === '' && $legacyKey !== null && isset($meta[$legacyKey])) {
            $id = trim((string) $meta[$legacyKey]);
        }

        return $id !== '' ? $id : null;
    }

    /**
     * @return array{url: ?string, name: ?string, mime: ?string}
     */
    protected function quizQuestionImagePayload(?string $materialPublicId, User $user): array
    {
        if ($materialPublicId === null) {
            return ['url' => null, 'name' => null, 'mime' => null];
        }

        $material = LessonMaterial::query()->where('public_id', $materialPublicId)->first();
        if ($material === null) {
            return ['url' => null, 'name' => null, 'mime' => null];
        }

        $formatted = $this->formatLessonMaterial($material, $user);

        return [
            'url' => $formatted['fileUrl'] ?? $formatted['inlineFileUrl'] ?? null,
            'name' => $formatted['name'] ?? null,
            'mime' => isset($formatted['mime']) ? (string) $formatted['mime'] : null,
        ];
    }

    /**
     * Stored under `settings_json` (merged with defaults for API output).
     *
     * @return array<string, mixed>
     */
    protected function quizAuthoringDefaults(): array
    {
        return [
            'timeUnit' => 'minutes',
            'quizStyle' => 'global',
            'randomizeQuestions' => false,
            'randomizeAnswers' => false,
            'showCorrectAnswer' => false,
            'quizAttemptHistory' => false,
            'retakeAfterPass' => false,
            'limitedRetakeAttempts' => false,
            'passingGrade' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    protected function normalizeQuizAuthoringFromRow(Quiz $q, ?array $stored): array
    {
        $merged = array_merge($this->quizAuthoringDefaults(), is_array($stored) ? $stored : []);

        $timeUnit = (($merged['timeUnit'] ?? 'minutes') === 'hours') ? 'hours' : 'minutes';
        $merged['timeUnit'] = $timeUnit;

        $dm = max(0, (int) $q->duration_minutes);
        $displayDuration = $timeUnit === 'hours'
            ? (int) max(0, (int) round($dm / 60))
            : $dm;
        $merged['duration'] = $displayDuration;

        $pg = $merged['passingGrade'] ?? 0;
        $merged['passingGrade'] = max(0, min(100, (int) $pg));
        unset($merged['pointsCutAfterRetake']);

        return $merged;
    }

    /**
     * Prefer the live number of `questions` rows (via `withCount('questions')`) over the denormalized `quizzes.question_count` column.
     */
    protected function resolvedQuizQuestionCount(Quiz $q): int
    {
        return isset($q->questions_count)
            ? (int) $q->questions_count
            : (int) $q->question_count;
    }

    protected function formatQuiz(Quiz $q, User $user): array
    {
        $attempts = QuizAttempt::query()
            ->where('user_id', $user->id)
            ->where('quiz_id', $q->id);

        $authoring = $this->normalizeQuizAuthoringFromRow(
            $q,
            is_array($q->settings_json) ? $q->settings_json : null
        );

        return [
            'id' => $q->public_id,
            'courseId' => $q->course->public_id,
            'moduleId' => $q->module?->public_id,
            'title' => $q->title,
            'durationMinutes' => (int) $q->duration_minutes,
            'attemptsAllowed' => (int) $q->attempts_allowed,
            'attemptsUsed' => (int) $attempts->count(),
            'questionCount' => $this->resolvedQuizQuestionCount($q),
            'sortOrder' => (int) $q->sort_order,
            'questionPoolCount' => (int) $q->question_pool_count,
            'bestScore' => (int) ($attempts->max('score') ?? 0),
            'shortDescription' => (string) ($q->description ?? ''),
            'duration' => (int) ($authoring['duration'] ?? 0),
            'timeUnit' => (string) ($authoring['timeUnit'] ?? 'minutes'),
            'quizStyle' => (string) ($authoring['quizStyle'] ?? 'global'),
            'passingGrade' => (int) ($authoring['passingGrade'] ?? 0),
            'randomizeQuestions' => (bool) ($authoring['randomizeQuestions'] ?? false),
            'randomizeAnswers' => (bool) ($authoring['randomizeAnswers'] ?? false),
            'showCorrectAnswer' => (bool) ($authoring['showCorrectAnswer'] ?? false),
            'quizAttemptHistory' => (bool) ($authoring['quizAttemptHistory'] ?? false),
            'retakeAfterPass' => (bool) ($authoring['retakeAfterPass'] ?? false),
            'limitedRetakeAttempts' => (bool) ($authoring['limitedRetakeAttempts'] ?? false),
        ];
    }

    protected function formatQuizAttempt(QuizAttempt $a): array
    {
        return [
            'id' => $a->public_id,
            'quizId' => $a->quiz->public_id,
            'date' => $a->attempted_on->format('Y-m-d'),
            'score' => (int) $a->score,
            'durationUsed' => $a->duration_used_label,
            'correctAnswers' => (int) $a->correct_answers,
            'totalQuestions' => (int) $a->total_questions,
        ];
    }

    public function authoringAssignmentPayload(Assignment $assignment, User $user): array
    {
        $assignment->loadMissing(['course', 'module', 'lessonMaterials']);
        $assignment->loadCount('questions');

        return $this->formatAssignment($assignment, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatAssignmentQuestion(AssignmentQuestion $q): array
    {
        return [
            'id' => (string) $q->id,
            'prompt' => $q->prompt,
            'questionType' => 'single_choice',
            'options' => $q->relationLoaded('options')
                ? $q->options->map(function (AssignmentQuestionOption $opt) {
                    return [
                        'id' => (string) $opt->id,
                        'label' => $opt->label,
                        'isCorrect' => (bool) $opt->is_correct,
                    ];
                })->values()->all()
                : [],
        ];
    }

    protected function normalizeAssignmentAuthoringFromRow(Assignment $a, ?array $stored): array
    {
        $merged = is_array($stored) ? $stored : [];
        $timeUnit = (($merged['timeUnit'] ?? 'minutes') === 'hours') ? 'hours' : 'minutes';
        $dm = max(0, (int) $a->duration_minutes);
        $displayDuration = $timeUnit === 'hours'
            ? (int) max(0, (int) round($dm / 60))
            : $dm;

        return [
            'timeUnit' => $timeUnit,
            'resetTimeLimitOnRetake' => (bool) ($merged['resetTimeLimitOnRetake'] ?? false),
            'lessonPreview' => (bool) ($merged['lessonPreview'] ?? false),
            'duration' => $displayDuration,
            'passingGrade' => max(0, min(100, (int) ($merged['passingGrade'] ?? 70))),
        ];
    }

    protected function resolvedAssignmentQuestionCount(Assignment $a): int
    {
        return isset($a->questions_count)
            ? (int) $a->questions_count
            : (int) $a->question_count;
    }

    protected function formatAssignment(Assignment $a, User $user): array
    {
        $authoring = $this->normalizeAssignmentAuthoringFromRow(
            $a,
            is_array($a->settings_json) ? $a->settings_json : null
        );

        $attempts = AssignmentAttempt::query()
            ->where('user_id', $user->id)
            ->where('assignment_id', $a->id);

        return [
            'id' => $a->public_id,
            'courseId' => $a->course->public_id,
            'moduleId' => $a->module?->public_id,
            'title' => $a->title,
            'lessonContentHtml' => (string) ($a->content_html ?? ''),
            'attemptsAllowed' => (int) $a->attempts_allowed,
            'attemptsUsed' => (int) $attempts->count(),
            'bestScore' => (int) ($attempts->max('score') ?? 0),
            'passingGrade' => (int) ($authoring['passingGrade'] ?? 70),
            'durationMinutes' => (int) $a->duration_minutes,
            'duration' => (int) ($authoring['duration'] ?? 0),
            'timeUnit' => (string) ($authoring['timeUnit'] ?? 'minutes'),
            'resetTimeLimitOnRetake' => (bool) ($authoring['resetTimeLimitOnRetake'] ?? false),
            'lessonPreview' => (bool) ($authoring['lessonPreview'] ?? false),
            'questionCount' => $this->resolvedAssignmentQuestionCount($a),
            'sortOrder' => (int) $a->sort_order,
            'materials' => $a->relationLoaded('lessonMaterials')
                ? $a->lessonMaterials
                    ->map(fn (LessonMaterial $f) => $this->formatLessonMaterial($f, $user))
                    ->values()
                    ->all()
                : [],
        ];
    }

    protected function formatEnrollment(Enrollment $e, bool $includeLearner = false): array
    {
        $e->loadMissing(['program', 'course', 'learningMode']);

        $row = [
            'id' => $e->public_id,
            'programId' => $e->program?->public_id ?? '',
            'programTitle' => $e->program?->title ?? '',
            'courseId' => $e->course?->public_id,
            'courseTitle' => $e->course?->title ?? '',
            'learningModeId' => $e->learningMode?->public_id,
            'learningModeName' => $e->learningMode?->name ?: ($e->learningMode?->label ?? ''),
            'submittedAt' => optional($e->submitted_at)->format('Y-m-d'),
            'status' => $e->status,
            'requestKind' => is_array($e->form_data) && ($e->form_data['requestKind'] ?? '') === 'course_access'
                ? 'course_access'
                : ($e->course_id ? 'course' : 'program'),
        ];

        if ($includeLearner) {
            $student = $e->user?->studentProfile;
            $row['userName'] = $e->user->name ?? '';
            $row['userEmail'] = $e->user->email ?? '';
            $row['phoneNumber'] = $student?->phone_number ?? '';
            $row['schoolHeld'] = $student?->school_held ?? '';
            $row['aliasName'] = $student?->alias_name ?? '';
            $formData = $e->form_data;
            $row['hasFormData'] = is_array($formData) && $formData !== [];
            $this->appendPartialPaymentFields($row, $formData);
        }

        if ($e->payment_proof_path) {
            $row['hasPaymentProof'] = true;
            $row['paymentProofFileName'] = $e->payment_proof_original_name ?? 'payment-proof';
            if ($includeLearner) {
                $row['paymentProofUrl'] = '/api/enrollments/'.$e->public_id.'/payment-proof';
            }
        }

        if (! $includeLearner) {
            $formData = $e->form_data;
            if (is_array($formData) && $formData !== []) {
                $row['formData'] = $formData;
            }
            $this->appendPartialPaymentFields($row, is_array($formData) ? $formData : []);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $formData
     */
    protected function appendPartialPaymentFields(array &$row, ?array $formData): void
    {
        if (! is_array($formData)) {
            return;
        }

        $partialPayments = is_array($formData['partialPayments'] ?? null) ? $formData['partialPayments'] : [];
        $summary = \App\Support\EnrollmentPayments::summarizeFormDataPayments($formData);
        $initialDownpayment = \App\Support\EnrollmentPayments::parseMoneyAmount($formData['downpaymentAmount'] ?? null);

        if ($partialPayments !== []) {
            $row['partialPayments'] = $partialPayments;
        }

        if (isset($formData['downpaymentVerificationStatus'])) {
            $row['downpaymentVerificationStatus'] = (string) $formData['downpaymentVerificationStatus'];
        }

        if ($initialDownpayment > 0) {
            $row['initialDownpayment'] = $initialDownpayment;
        }

        if ($summary['verifiedTotal'] > 0) {
            $row['totalPaid'] = $summary['verifiedTotal'];
        }

        if ($summary['hasUnreviewed']) {
            $row['hasUnreviewedPayments'] = true;
        }
    }

    protected function parseMoneyAmount(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return max(0, (float) $value);
        }

        if (! is_string($value) || trim($value) === '') {
            return 0.0;
        }

        $cleaned = preg_replace('/[^\d.]/', '', $value) ?? '';
        if ($cleaned === '' || ! is_numeric($cleaned)) {
            return 0.0;
        }

        return max(0, (float) $cleaned);
    }

    protected function formatAdmin(): array
    {
        $users = User::query()->with('lmsProfile.program')->orderBy('name')->get()->map(function (User $u) {
            $prog = $u->lmsProfile?->program?->title ?? $u->lmsProfile?->program?->code ?? '';

            return [
                'id' => 'user-'.$u->id,
                'name' => $u->name,
                'role' => match ($u->role) {
                    'student' => 'Learner',
                    'instructor' => 'Instructor',
                    default => ucfirst($u->role),
                },
                'activeProgram' => $prog,
                'status' => 'Active',
            ];
        })->all();

        $uploads = AdminUpload::query()->orderBy('created_at', 'desc')->get()->map(fn ($u) => [
            'id' => $u->public_id,
            'title' => $u->title,
            'type' => $u->asset_type,
            'status' => $u->status,
        ])->all();

        return [
            'users' => $users,
            'uploads' => $uploads,
        ];
    }

    protected function buildAnalytics(User $user): array
    {
        $courses = Course::query()->with('modules')->get();
        $completed = 0;
        $total = 0;
        foreach ($courses as $course) {
            $n = $course->modules->count();
            $total += $n;
            $completed += UserModuleProgress::query()
                ->where('user_id', $user->id)
                ->whereIn('module_id', $course->modules->pluck('id'))
                ->where('progress_percent', '>=', 100)
                ->count();
        }

        $completionRate = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        $payload = [
            'completedModules' => $completed,
            'totalModules' => $total,
            'completionRate' => $completionRate,
            'pendingModules' => max(0, $total - $completed),
            'strengths' => ['Hydraulics', 'Code Familiarity', 'Material Selection'],
            'weaknesses' => ['Open channel flow', 'Sanitary vent layouts', 'Heat treatment cycles'],
            'suggestedModuleIds' => ['module-hydraulics-practice', 'module-code-final-coaching', 'module-heat-treatment-refresher'],
        ];

        if ($this->userCanViewInstructorDashboard($user)) {
            $payload['instructorSummary'] = $this->buildInstructorDashboardStats();
        }

        return $payload;
    }

    /**
     * @return array{programs: int, enrollments: int, students: int}
     */
    protected function buildInstructorDashboardStats(): array
    {
        return Cache::remember('lms:instructor-dashboard-stats', now()->addSeconds(45), function () {
            return [
                'programs' => Program::query()
                    ->where(function ($q) {
                        $q->whereNull('status')
                            ->orWhereRaw('LOWER(status) = ?', ['active']);
                    })
                    ->count(),
                'enrollments' => Enrollment::query()->count(),
                'students' => Student::query()->count(),
            ];
        });
    }

    protected function userCanViewInstructorDashboard(User $user): bool
    {
        $role = strtolower(trim((string) ($user->role ?? '')));

        return $role === 'instructor' || $role === 'admin';
    }

    public function userCanViewGradebook(User $user): bool
    {
        return $this->userCanViewInstructorDashboard($user);
    }

    /** @return array<int, array{id: string, title: string}> */
    public function gradebookCoursesForStaff(): array
    {
        return Course::query()
            ->whereNotNull('program_id')
            ->orderBy('title')
            ->orderBy('id')
            ->get(['public_id', 'title'])
            ->map(fn (Course $course) => [
                'id' => $course->public_id,
                'title' => $course->title,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     course: array{id: string, title: string},
     *     stats: array<int, array{id: string, label: string, value: string}>,
     *     data: array<int, array<string, mixed>>,
     *     meta: array<string, mixed>
     * }
     */
    public function gradebookPaginatedForCourse(Course $course, int $page, int $perPage): array
    {
        $course->loadMissing(['modules.resources']);
        $curriculum = $this->gradebookCurriculumForCourse($course);
        $enrolledUserIds = $this->enrolledUserIdsForCourse($course);

        $users = User::query()
            ->whereIn('id', $enrolledUserIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'public_uid', 'name', 'email']);

        $studentCount = $users->count();
        $lessonKeys = $curriculum['lessonKeys'];
        $totalLessons = (int) $curriculum['totalLessons'];
        $quizzes = $curriculum['quizzes'];
        $totalQuizzes = (int) $curriculum['totalQuizzes'];
        $assignments = $curriculum['assignments'];
        $totalAssignments = (int) $curriculum['totalAssignments'];

        $quizIds = $quizzes->pluck('id')->map(fn ($id) => (int) $id)->all();
        $assignmentIds = $assignments->pluck('id')->map(fn ($id) => (int) $id)->all();

        $quizPassingById = [];
        foreach ($quizzes as $quiz) {
            $quizPassingById[(int) $quiz->id] = $this->quizPassingGrade($quiz);
        }

        $assignmentPassingById = [];
        foreach ($assignments as $assignment) {
            $assignmentPassingById[(int) $assignment->id] = $this->assignmentPassingGrade($assignment);
        }

        $bestQuizByUser = [];
        if ($quizIds !== [] && $enrolledUserIds !== []) {
            $quizAttempts = QuizAttempt::query()
                ->whereIn('quiz_id', $quizIds)
                ->whereIn('user_id', $enrolledUserIds)
                ->get(['user_id', 'quiz_id', 'score']);

            foreach ($quizAttempts as $attempt) {
                $uid = (int) $attempt->user_id;
                $qid = (int) $attempt->quiz_id;
                $score = (int) $attempt->score;
                $bestQuizByUser[$uid][$qid] = max($bestQuizByUser[$uid][$qid] ?? 0, $score);
            }
        }

        $bestAssignmentByUser = [];
        if ($assignmentIds !== [] && $enrolledUserIds !== []) {
            $assignmentAttempts = AssignmentAttempt::query()
                ->whereIn('assignment_id', $assignmentIds)
                ->whereIn('user_id', $enrolledUserIds)
                ->get(['user_id', 'assignment_id', 'score']);

            foreach ($assignmentAttempts as $attempt) {
                $uid = (int) $attempt->user_id;
                $aid = (int) $attempt->assignment_id;
                $score = (int) $attempt->score;
                $bestAssignmentByUser[$uid][$aid] = max($bestAssignmentByUser[$uid][$aid] ?? 0, $score);
            }
        }

        $completedLessonsByUser = [];
        if ($lessonKeys !== [] && $enrolledUserIds !== []) {
            $lessonRows = UserLessonProgress::query()
                ->where('course_id', $course->id)
                ->whereIn('user_id', $enrolledUserIds)
                ->whereIn('lesson_key', $lessonKeys)
                ->get(['user_id', 'lesson_key']);

            foreach ($lessonRows as $row) {
                $uid = (int) $row->user_id;
                $key = (string) $row->lesson_key;
                $completedLessonsByUser[$uid][$key] = true;
            }
        }

        $startedAtByUser = $this->gradebookStartedAtByUser($course, $enrolledUserIds);
        $subscriptionCount = $this->gradebookSubscriptionEnrollmentCount($course, $enrolledUserIds);

        $rows = [];
        $progressValues = [];
        $passedLessonSlots = 0;
        $passedQuizSlots = 0;
        $passedAssignmentSlots = 0;

        foreach ($users as $user) {
            $uid = (int) $user->id;
            $lessonsCompleted = 0;
            foreach ($lessonKeys as $lessonKey) {
                if (isset($completedLessonsByUser[$uid][$lessonKey])) {
                    $lessonsCompleted++;
                }
            }

            $quizzesPassed = 0;
            foreach ($quizzes as $quiz) {
                $qid = (int) $quiz->id;
                $best = (int) ($bestQuizByUser[$uid][$qid] ?? 0);
                if ($best >= ($quizPassingById[$qid] ?? 70)) {
                    $quizzesPassed++;
                }
            }

            $assignmentsPassed = 0;
            foreach ($assignments as $assignment) {
                $aid = (int) $assignment->id;
                $best = (int) ($bestAssignmentByUser[$uid][$aid] ?? 0);
                if ($best >= ($assignmentPassingById[$aid] ?? 70)) {
                    $assignmentsPassed++;
                }
            }

            $passedLessonSlots += $lessonsCompleted;
            $passedQuizSlots += $quizzesPassed;
            $passedAssignmentSlots += $assignmentsPassed;

            $totalItems = $totalLessons + $totalQuizzes + $totalAssignments;
            $completedItems = $lessonsCompleted + $quizzesPassed + $assignmentsPassed;
            $progressPercent = $totalItems > 0 ? (int) round(($completedItems / $totalItems) * 100) : 0;
            $progressValues[] = $progressPercent;

            $rows[] = [
                'id' => $user->public_uid,
                'name' => $user->name,
                'email' => $user->email,
                'lessons' => "{$lessonsCompleted}/{$totalLessons}",
                'quizzes' => "{$quizzesPassed}/{$totalQuizzes}",
                'assignments' => "{$assignmentsPassed}/{$totalAssignments}",
                'progress' => "{$progressPercent}%",
                'startedAt' => $startedAtByUser[$uid] ?? '—',
            ];
        }

        $averageProgress = $progressValues === []
            ? 0.0
            : array_sum($progressValues) / count($progressValues);

        $lessonSlotTotal = $studentCount * $totalLessons;
        $quizSlotTotal = $studentCount * $totalQuizzes;
        $assignmentSlotTotal = $studentCount * $totalAssignments;

        $stats = [
            [
                'id' => 'students',
                'label' => 'All time course students',
                'value' => (string) $studentCount,
            ],
            [
                'id' => 'avg',
                'label' => 'Course average progress',
                'value' => $this->formatGradebookPercent($averageProgress),
            ],
            [
                'id' => 'quizzes',
                'label' => 'Course passed quizzes',
                'value' => $this->formatGradebookPercent(
                    $quizSlotTotal > 0 ? ($passedQuizSlots / $quizSlotTotal) * 100 : 0.0
                ),
            ],
            [
                'id' => 'lessons',
                'label' => 'Course passed lessons',
                'value' => $this->formatGradebookPercent(
                    $lessonSlotTotal > 0 ? ($passedLessonSlots / $lessonSlotTotal) * 100 : 0.0
                ),
            ],
            [
                'id' => 'sub',
                'label' => 'Course enrolled by subscription',
                'value' => (string) $subscriptionCount,
            ],
            [
                'id' => 'assign',
                'label' => 'Course passed assignments',
                'value' => $this->formatGradebookPercent(
                    $assignmentSlotTotal > 0 ? ($passedAssignmentSlots / $assignmentSlotTotal) * 100 : 0.0
                ),
            ],
        ];

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($rows, $offset, $perPage);
        $sliceCount = count($slice);

        return [
            'course' => [
                'id' => $course->public_id,
                'title' => $course->title,
            ],
            'stats' => $stats,
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => $total === 0 ? 0 : $offset + $sliceCount,
            ],
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function quizStudentProgressForExport(
        Quiz $quiz,
        string $status,
        ?string $search = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $payload = $this->quizStudentProgressPaginated($quiz, $status, 1, 100000, $search);
        $rows = ExportDateRange::filterAssocRows($payload['data'] ?? [], $from, $to, 'attemptedOn');

        return array_map(static function (array $row) {
            $status = (string) ($row['status'] ?? '');
            $score = $status === 'pending' || $row['score'] === null ? '—' : ((int) $row['score']).'%';
            $attempted = $status === 'pending' ? 'Not attempted' : (string) ($row['attemptedOn'] ?? '—');
            $result = '—';
            if ($status === 'passed') {
                $result = 'Passed';
            } elseif ($status === 'non_passed') {
                $result = 'Not passed';
            }
            if (isset($row['correctAnswers'], $row['totalQuestions']) && $row['correctAnswers'] !== null) {
                $result = $result === '—'
                    ? $row['correctAnswers'].' / '.$row['totalQuestions'].' correct'
                    : $result.' ('.$row['correctAnswers'].' / '.$row['totalQuestions'].' correct)';
            }

            return [
                (string) ($row['name'] ?? ''),
                (string) ($row['email'] ?? ''),
                $score,
                $attempted,
                $result,
            ];
        }, $rows);
    }

    /**
     * @return list<list<string>>
     */
    public function quizLeaderboardForExport(
        Quiz $quiz,
        ?string $search = null,
        bool $useAliasNames = false,
        ?User $viewer = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $payload = $this->quizLeaderboardPaginated($quiz, 1, 100000, $search, $useAliasNames, $viewer);
        $rows = ExportDateRange::filterAssocRows($payload['data'] ?? [], $from, $to, 'attemptedOn');

        if ($useAliasNames) {
            return array_map(static fn (array $row) => [
                (string) ($row['rank'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['scoreLabel'] ?? (($row['score'] ?? '').'%')),
                (string) ($row['durationUsed'] ?? ''),
                (string) ($row['detailLabel'] ?? ((isset($row['passed']) && $row['passed']) ? 'Passed' : 'Failed')),
            ], $rows);
        }

        return array_map(static fn (array $row) => [
            (string) ($row['rank'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['scoreLabel'] ?? (($row['score'] ?? '').'%')),
            (string) ($row['durationUsed'] ?? ''),
            (string) ($row['detailLabel'] ?? ((isset($row['passed']) && $row['passed']) ? 'Passed' : 'Failed')),
            (string) ($row['attemptedOn'] ?? ''),
        ], $rows);
    }

    /**
     * @return list<list<string>>
     */
    public function assignmentStudentProgressForExport(
        Assignment $assignment,
        string $status,
        ?string $search = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $payload = $this->assignmentStudentProgressPaginated($assignment, $status, 1, 100000, $search);
        $rows = ExportDateRange::filterAssocRows($payload['data'] ?? [], $from, $to, 'attemptedOn');

        return array_map(static function (array $row) {
            $status = (string) ($row['status'] ?? '');
            $score = $status === 'pending' || $row['score'] === null ? '—' : ((int) $row['score']).'%';
            $submitted = $status === 'pending' ? 'Not submitted' : (string) ($row['attemptedOn'] ?? '—');
            $result = '—';
            if ($status === 'passed') {
                $result = 'Passed';
            } elseif ($status === 'non_passed') {
                $result = 'Not passed';
            }
            if (isset($row['correctAnswers'], $row['totalQuestions']) && $row['correctAnswers'] !== null) {
                $result = $result === '—'
                    ? $row['correctAnswers'].' / '.$row['totalQuestions'].' correct'
                    : $result.' ('.$row['correctAnswers'].' / '.$row['totalQuestions'].' correct)';
            }

            return [
                (string) ($row['name'] ?? ''),
                (string) ($row['email'] ?? ''),
                $score,
                $submitted,
                $result,
            ];
        }, $rows);
    }

    /**
     * @return list<list<string>>
     */
    public function assignmentLeaderboardForExport(
        Assignment $assignment,
        ?string $search = null,
        bool $useAliasNames = false,
        ?User $viewer = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        $payload = $this->assignmentLeaderboardPaginated($assignment, 1, 100000, $search, $useAliasNames, $viewer);
        $rows = ExportDateRange::filterAssocRows($payload['data'] ?? [], $from, $to, 'attemptedOn');

        if ($useAliasNames) {
            return array_map(static fn (array $row) => [
                (string) ($row['rank'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['scoreLabel'] ?? (($row['score'] ?? '').'%')),
                (string) ($row['durationUsed'] ?? ''),
                (string) ($row['detailLabel'] ?? ((isset($row['passed']) && $row['passed']) ? 'Passed' : 'Failed')),
            ], $rows);
        }

        return array_map(static fn (array $row) => [
            (string) ($row['rank'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['scoreLabel'] ?? (($row['score'] ?? '').'%')),
            (string) ($row['durationUsed'] ?? ''),
            (string) ($row['detailLabel'] ?? ((isset($row['passed']) && $row['passed']) ? 'Passed' : 'Failed')),
            (string) ($row['attemptedOn'] ?? ''),
        ], $rows);
    }

    /**
     * @return list<list<string>>
     */
    public function gradebookForExport(Course $course, ?string $from = null, ?string $to = null): array
    {
        $payload = $this->gradebookPaginatedForCourse($course, 1, 100000);
        $rows = ExportDateRange::filterAssocRows($payload['data'] ?? [], $from, $to, 'startedAt');

        return array_map(static fn (array $row) => [
            (string) ($row['name'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['lessons'] ?? ''),
            (string) ($row['quizzes'] ?? ''),
            (string) ($row['assignments'] ?? ''),
            (string) ($row['progress'] ?? ''),
            (string) ($row['startedAt'] ?? ''),
        ], $rows);
    }

    /**
     * @return list<list<string>>
     */
    public function quizSummariesForExport(
        ?string $from = null,
        ?string $to = null,
        ?string $search = null,
        ?string $courseId = null,
        ?string $status = null
    ): array {
        $rows = $this->quizSummariesForStaff($from, $to);
        $term = $search !== null ? strtolower(trim($search)) : '';
        $courseHint = $courseId !== null ? trim($courseId) : '';
        $statusHint = $status !== null ? trim($status) : '';

        $filtered = array_values(array_filter($rows, function (array $row) use ($term, $courseHint, $statusHint) {
            if ($term !== ''
                && ! str_contains(strtolower((string) ($row['title'] ?? '')), $term)
                && ! str_contains(strtolower((string) ($row['course'] ?? '')), $term)
            ) {
                return false;
            }
            if ($courseHint !== '' && $courseHint !== 'all' && (string) ($row['courseId'] ?? '') !== $courseHint) {
                return false;
            }
            if ($statusHint === 'pending' && (int) ($row['pending'] ?? 0) <= 0) {
                return false;
            }
            if ($statusHint === 'issues' && (int) ($row['nonPassed'] ?? 0) <= 0) {
                return false;
            }

            return true;
        }));

        return array_map(static fn (array $row) => [
            (string) ($row['title'] ?? ''),
            (string) ($row['course'] ?? ''),
            (string) ($row['total'] ?? ''),
            (string) ($row['passed'] ?? ''),
            (string) ($row['nonPassed'] ?? ''),
            (string) ($row['pending'] ?? ''),
        ], $filtered);
    }

    /**
     * @return list<list<string>>
     */
    public function assignmentSummariesForExport(
        ?string $from = null,
        ?string $to = null,
        ?string $search = null,
        ?string $courseId = null,
        ?string $status = null
    ): array {
        $rows = $this->assignmentSummariesForStaff($from, $to);
        $term = $search !== null ? strtolower(trim($search)) : '';
        $courseHint = $courseId !== null ? trim($courseId) : '';
        $statusHint = $status !== null ? trim($status) : '';

        $filtered = array_values(array_filter($rows, function (array $row) use ($term, $courseHint, $statusHint) {
            if ($term !== ''
                && ! str_contains(strtolower((string) ($row['title'] ?? '')), $term)
                && ! str_contains(strtolower((string) ($row['course'] ?? '')), $term)
            ) {
                return false;
            }
            if ($courseHint !== '' && $courseHint !== 'all' && (string) ($row['courseId'] ?? '') !== $courseHint) {
                return false;
            }
            if ($statusHint === 'pending' && (int) ($row['pending'] ?? 0) <= 0) {
                return false;
            }
            if ($statusHint === 'issues' && (int) ($row['nonPassed'] ?? 0) <= 0) {
                return false;
            }

            return true;
        }));

        return array_map(static fn (array $row) => [
            (string) ($row['title'] ?? ''),
            (string) ($row['course'] ?? ''),
            (string) ($row['total'] ?? ''),
            (string) ($row['passed'] ?? ''),
            (string) ($row['nonPassed'] ?? ''),
            (string) ($row['pending'] ?? ''),
        ], $filtered);
    }

    /**
     * @return list<list<string>>
     */
    public function quizResultsForExport(User $target, ?string $from = null, ?string $to = null, ?string $quizPublicId = null): array
    {
        $rows = $this->quizResultsForUser($target, $from, $to);
        $hint = $quizPublicId !== null ? trim($quizPublicId) : '';
        if ($hint !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row) => (string) ($row['quizId'] ?? '') === $hint
            ));
        }

        return array_map(static fn (array $row) => [
            (string) ($row['date'] ?? ''),
            (string) ($row['quizId'] ?? ''),
            isset($row['score']) ? ((int) $row['score']).'%' : '',
            ($row['correctAnswers'] ?? '').'/'.($row['totalQuestions'] ?? ''),
            (string) ($row['durationUsed'] ?? ''),
        ], $rows);
    }

    /**
     * @return list<list<string>>
     */
    public function leaderboardForExport(string $period, ?string $from = null, ?string $to = null): array
    {
        $rows = $this->leaderboardForPeriod($period, $from, $to);

        return array_map(static fn (array $row, int $index) => [
            (string) ($index + 1),
            (string) ($row['name'] ?? ''),
            (string) ($row['program'] ?? ''),
            (string) ($row['score'] ?? ''),
            (string) ($row['badge'] ?? ''),
        ], $rows, array_keys($rows));
    }

    /**
     * @return list<list<string>>
     */
    public function adminUsersForExport(?string $from = null, ?string $to = null): array
    {
        $query = User::query()->with('lmsProfile.program')->orderBy('name');
        ExportDateRange::constrain($query, $from, $to);
        $users = $query->get();

        return $users->map(function (User $u) {
            $prog = $u->lmsProfile?->program?->title ?? $u->lmsProfile?->program?->code ?? '';

            return [
                (string) $u->name,
                match ($u->role) {
                    'student' => 'Learner',
                    'instructor' => 'Instructor',
                    default => ucfirst((string) $u->role),
                },
                (string) $prog,
                (string) ($u->status ?? 'Active'),
            ];
        })->values()->all();
    }

    /**
     * @return list<list<string>>
     */
    public function adminEnrollmentsForExport(?string $from = null, ?string $to = null): array
    {
        $query = Enrollment::query()->with('program')->orderByDesc('submitted_at')->orderByDesc('id');
        ExportDateRange::constrainCoalesce($query, $from, $to);

        return $query->get()->map(fn (Enrollment $e) => [
            (string) ($e->program?->title ?? ''),
            optional($e->submitted_at ?? $e->created_at)->format('Y-m-d') ?? '',
            (string) ($e->status ?? ''),
        ])->values()->all();
    }

    /**
     * @return array{
     *     lessonKeys: array<int, string>,
     *     totalLessons: int,
     *     quizzes: \Illuminate\Support\Collection<int, Quiz>,
     *     totalQuizzes: int,
     *     assignments: \Illuminate\Support\Collection<int, Assignment>,
     *     totalAssignments: int
     * }
     */
    protected function gradebookCurriculumForCourse(Course $course): array
    {
        $modules = $this->modulesForCourseStats($course);
        $moduleIds = $modules->pluck('id')->map(fn ($id) => (int) $id)->all();

        $lessonKeys = [];
        foreach ($modules as $module) {
            $lessonKeys[] = $module->public_id.'-core';
            foreach ($module->resources->where('is_standalone_lesson', true) as $resource) {
                $lessonKeys[] = (string) $resource->public_id;
            }
        }
        $lessonKeys = array_values(array_unique($lessonKeys));

        $quizzes = Quiz::query()
            ->where('course_id', $course->id)
            ->when($moduleIds !== [], function ($query) use ($moduleIds) {
                $query->where(function ($sub) use ($moduleIds) {
                    $sub->whereIn('module_id', $moduleIds)->orWhereNull('module_id');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $assignments = Assignment::query()
            ->where('course_id', $course->id)
            ->when($moduleIds !== [], fn ($query) => $query->whereIn('module_id', $moduleIds))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'lessonKeys' => $lessonKeys,
            'totalLessons' => count($lessonKeys),
            'quizzes' => $quizzes,
            'totalQuizzes' => $quizzes->count(),
            'assignments' => $assignments,
            'totalAssignments' => $assignments->count(),
        ];
    }

    /** @return array<int, string> */
    protected function gradebookStartedAtByUser(Course $course, array $enrolledUserIds): array
    {
        if ($enrolledUserIds === []) {
            return [];
        }

        $rows = Enrollment::query()
            ->approved()
            ->where('course_id', $course->id)
            ->whereIn('user_id', $enrolledUserIds)
            ->whereNotNull('submitted_at')
            ->orderBy('submitted_at')
            ->get(['user_id', 'submitted_at']);

        $byUser = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            if (isset($byUser[$uid])) {
                continue;
            }
            $date = $row->submitted_at;
            $byUser[$uid] = $date !== null ? $date->format('j F, Y') : '—';
        }

        return $byUser;
    }

    protected function gradebookSubscriptionEnrollmentCount(Course $course, array $enrolledUserIds): int
    {
        if ($course->program_id === null || $enrolledUserIds === []) {
            return 0;
        }

        return Enrollment::query()
            ->approved()
            ->where('course_id', $course->id)
            ->whereIn('user_id', $enrolledUserIds)
            ->get(['user_id', 'form_data'])
            ->filter(function (Enrollment $enrollment) {
                $form = is_array($enrollment->form_data) ? $enrollment->form_data : [];
                $packageId = $form['packageEnrollId'] ?? $form['package_enroll_id'] ?? null;

                if (is_string($packageId)) {
                    return trim($packageId) !== '';
                }

                return ! empty($packageId);
            })
            ->pluck('user_id')
            ->unique()
            ->count();
    }

    protected function formatGradebookPercent(float $value, int $decimals = 2): string
    {
        $clamped = max(0.0, min(100.0, $value));

        return number_format($clamped, $decimals, '.', '').'%';
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, string>
     */
    protected function aliasNamesByUserIdForCourse(Course $course, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $aliases = [];
        $students = Student::query()
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'alias_name']);

        foreach ($students as $student) {
            $alias = trim((string) ($student->alias_name ?? ''));
            if ($alias !== '') {
                $aliases[(int) $student->user_id] = $alias;
            }
        }

        $missing = [];
        foreach ($userIds as $userId) {
            if (! isset($aliases[(int) $userId])) {
                $missing[] = (int) $userId;
            }
        }

        if ($missing === []) {
            return $aliases;
        }

        $rows = Enrollment::query()
            ->whereIn('user_id', $missing)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get(['user_id', 'form_data']);

        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            if (isset($aliases[$uid])) {
                continue;
            }

            $form = is_array($row->form_data) ? $row->form_data : [];
            $alias = trim((string) ($form['aliasName'] ?? $form['alias_name'] ?? ''));
            if ($alias !== '') {
                $aliases[$uid] = $alias;
            }
        }

        return $aliases;
    }
}
