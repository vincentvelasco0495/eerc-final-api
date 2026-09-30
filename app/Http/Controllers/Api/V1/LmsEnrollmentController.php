<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\BatchEnroll;
use App\Models\BranchEnroll;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\HonorAwardDiscount;
use App\Models\LearningMode;
use App\Models\PackageEnroll;
use App\Models\Program;
use App\Models\ReviewSchedule;
use App\Models\User;
use App\Services\EnrollmentSchemaService;
use App\Services\EnrollmentNotificationService;
use App\Services\LmsCatalogService;
use App\Support\EnrollmentPayments;
use App\Support\SimpleXlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LmsEnrollmentController extends Controller
{
    private const ENROLLMENT_DOCUMENT_KEYS = [
        'profilePicture',
        'discountProof',
        'retakerProof',
        'paymentProof',
        'signature',
    ];
    use ResolvesLmsActor;

    public function index(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor();

        if ($request->filled('page')) {
            $validated = $request->validate([
                'page' => ['required', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
                'course' => ['sometimes', 'nullable', 'string', 'max:64'],
                'status' => ['sometimes', 'nullable', 'string', 'in:pending,approved,rejected,hold'],
            ]);

            $perPage = (int) ($validated['per_page'] ?? 10);
            $page = (int) $validated['page'];
            $search = isset($validated['search']) ? (string) $validated['search'] : null;
            $courseFilter = isset($validated['course']) ? (string) $validated['course'] : null;
            $statusFilter = isset($validated['status']) ? (string) $validated['status'] : null;
            $userId = $this->canManageEnrollments($user) ? null : ($user->id > 0 ? $user->id : 0);

            if ($userId === 0) {
                return response()->json([
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                        'from' => 0,
                        'to' => 0,
                    ],
                ]);
            }

            $groupByLearner = $userId === null && trim($courseFilter ?? '') === '';

            return response()->json(
                $catalog->enrollmentsPaginated(
                    $page,
                    $perPage,
                    $search,
                    $userId,
                    $courseFilter,
                    $statusFilter,
                    $groupByLearner
                )
            );
        }

        if ($user->id <= 0) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $catalog->enrollmentsForUser($user)]);
    }

    public function enrolledCourses(LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor();
        if ($user->id <= 0) {
            abort(401, 'Authentication required.');
        }

        return response()->json(['data' => $catalog->enrolledCoursesForUser($user)]);
    }

    public function export(Request $request, LmsCatalogService $catalog)
    {
        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        if (! $this->canManageEnrollments($actor)) {
            abort(403, 'You cannot export enrollments.');
        }

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $search = isset($validated['search']) ? (string) $validated['search'] : null;

        $headers = $catalog->enrollmentExportHeaders();
        $rows = $catalog->enrollmentsForExport($search);
        $binary = SimpleXlsx::build('Enrollments', $headers, $rows);
        $filename = 'enrollments-'.now()->format('Y-m-d').'.xlsx';

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->has('form_data')) {
            return $this->storeFullApplication($request);
        }

        $data = $request->validate([
            'course_id' => 'required_without:program_id|nullable|string|max:64',
            'program_id' => 'required_without:course_id|nullable|string|max:64',
            'payment_proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $user = $this->lmsActor();
        if ($user->id <= 0) {
            abort(401, 'Authentication required.');
        }

        $uploaded = $request->file('payment_proof');

        if (! empty($data['course_id'])) {
            return $this->storeCourseEnrollment($user, (string) $data['course_id'], $uploaded);
        }

        return $this->storeProgramEnrollment($user, (string) $data['program_id'], $uploaded);
    }

    public function storeFullApplication(Request $request): JsonResponse
    {
        EnrollmentSchemaService::ensureFormDataColumns();
        EnrollmentSchemaService::ensureBatchEnrollIdColumn();
        EnrollmentSchemaService::ensureLearningModeIdColumn();
        EnrollmentSchemaService::ensureBranchEnrollIdColumn();
        EnrollmentSchemaService::ensureReviewScheduleIdColumn();
        EnrollmentSchemaService::ensureHonorAwardDiscountIdColumn();
        EnrollmentSchemaService::ensurePackageEnrollIdColumn();

        $data = $request->validate([
            'program_id' => ['required', 'string', 'max:64'],
            'form_data' => ['required', 'string'],
            'profile_picture' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx', 'max:10240'],
            'payment_proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx', 'max:10240'],
            'signature' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'discount_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx', 'max:10240'],
            'retaker_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx', 'max:10240'],
        ]);

        $user = $this->lmsActor();
        if ($user->id <= 0) {
            abort(401, 'Authentication required.');
        }

        $formData = json_decode((string) $data['form_data'], true);
        if (! is_array($formData)) {
            abort(422, 'Invalid form data.');
        }

        $program = Program::query()->where('public_id', (string) $data['program_id'])->firstOrFail();

        $publishedCourseCount = Course::query()
            ->where('program_id', $program->id)
            ->where('is_published', true)
            ->count();
        if ($publishedCourseCount === 0) {
            abort(422, 'This program has no published courses available for enrollment.');
        }

        $existing = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('program_id', $program->id)
            ->whereNull('course_id')
            ->orderByDesc('id')
            ->first();

        if ($existing && in_array($existing->status, ['pending', 'approved', 'hold'], true)) {
            return response()->json([
                'message' => 'You already have an enrollment application for this program.',
            ], 409);
        }

        $documents = [];
        $documentFields = [
            'profile_picture' => 'profilePicture',
            'payment_proof' => 'paymentProof',
            'signature' => 'signature',
            'discount_proof' => 'discountProof',
            'retaker_proof' => 'retakerProof',
        ];

        foreach ($documentFields as $inputKey => $docKey) {
            $file = $request->file($inputKey);
            if ($file === null) {
                continue;
            }
            $stored = Storage::disk('public')->putFile('enrollment-documents', $file);
            $documents[$docKey] = [
                'path' => $stored,
                'originalName' => $file->getClientOriginalName() ?: $file->hashName(),
                'mime' => $file->getMimeType(),
            ];
        }

        $paymentProof = $documents['paymentProof'] ?? null;
        $proofPayload = [
            'payment_proof_path' => $paymentProof['path'] ?? null,
            'payment_proof_original_name' => $paymentProof['originalName'] ?? null,
            'payment_proof_mime' => $paymentProof['mime'] ?? null,
        ];

        if (EnrollmentPayments::parseMoneyAmount($formData['downpaymentAmount'] ?? null) > 0) {
            $formData['downpaymentVerificationStatus'] = EnrollmentPayments::STATUS_PENDING;
        }

        $payload = [
            'status' => 'pending',
            'submitted_at' => now()->toDateString(),
            'form_data' => $formData,
            'documents' => $documents,
            'batch_enroll_id' => BatchEnroll::resolveIdForProgram((int) $program->id, $formData),
            'learning_mode_id' => LearningMode::resolveId($formData),
            'branch_enroll_id' => BranchEnroll::resolveId($formData),
            'review_schedule_id' => ReviewSchedule::resolveId($formData),
            'honor_award_discount_id' => HonorAwardDiscount::resolveId($formData),
            'package_enroll_id' => PackageEnroll::resolveId($formData),
            ...$proofPayload,
        ];

        if ($existing && $existing->status === 'rejected') {
            $this->deleteEnrollmentDocuments($existing);
            $existing->update($payload);
            $enrollment = $existing;
        } else {
            $enrollment = Enrollment::query()->create([
                'public_id' => 'enrollment-'.Str::lower(Str::ulid()),
                'user_id' => $user->id,
                'program_id' => $program->id,
                'course_id' => null,
                ...$payload,
            ]);
        }

        $enrollment->load(['program', 'course']);

        LmsCatalogService::bustUserAnalyticsCache($user->id);
        $this->notifyManagersOfEnrollment($enrollment);

        return response()->json($this->formatStoreResponse($enrollment), 201);
    }

    protected function deleteEnrollmentDocuments(Enrollment $enrollment): void
    {
        $paths = [];
        if ($enrollment->payment_proof_path) {
            $paths[] = $enrollment->payment_proof_path;
        }
        $documents = is_array($enrollment->documents) ? $enrollment->documents : [];
        foreach ($documents as $doc) {
            if (is_array($doc) && ! empty($doc['path'])) {
                $paths[] = $doc['path'];
            }
        }
        foreach (array_unique($paths) as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    protected function storeCourseEnrollment(User $user, string $coursePublicId, $uploaded): JsonResponse
    {
        $course = Course::query()->where('public_id', $coursePublicId)->with('program')->firstOrFail();
        if (! $course->is_published) {
            abort(422, 'This course is not open for enrollment yet.');
        }
        $program = $course->program;
        if ($program === null) {
            abort(422, 'This course is not linked to a program.');
        }

        $existing = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->orderByDesc('id')
            ->first();

        if ($existing && in_array($existing->status, ['pending', 'approved', 'hold'], true)) {
            return response()->json([
                'message' => 'You already have an enrollment application for this course.',
            ], 409);
        }

        $stored = Storage::disk('public')->putFile('enrollment-payment-proofs', $uploaded);
        $proofPayload = [
            'payment_proof_path' => $stored,
            'payment_proof_original_name' => $uploaded->getClientOriginalName() ?: ('payment-'.$uploaded->hashName()),
            'payment_proof_mime' => $uploaded->getMimeType(),
        ];

        if ($existing && $existing->status === 'rejected') {
            if ($existing->payment_proof_path) {
                Storage::disk('public')->delete($existing->payment_proof_path);
            }

            $existing->update([
                'status' => 'pending',
                'submitted_at' => now()->toDateString(),
                'program_id' => $program->id,
                'course_id' => $course->id,
                ...$proofPayload,
            ]);
            $enrollment = $existing;
        } else {
            $enrollment = Enrollment::query()->create([
                'public_id' => 'enrollment-'.Str::lower(Str::ulid()),
                'user_id' => $user->id,
                'program_id' => $program->id,
                'course_id' => $course->id,
                'status' => 'pending',
                'submitted_at' => now()->toDateString(),
                ...$proofPayload,
            ]);
        }

        $enrollment->load(['program', 'course']);

        LmsCatalogService::bustUserAnalyticsCache($user->id);
        $this->notifyManagersOfEnrollment($enrollment);

        return response()->json($this->formatStoreResponse($enrollment), 201);
    }

    protected function storeProgramEnrollment(User $user, string $programPublicId, $uploaded): JsonResponse
    {
        $program = Program::query()->where('public_id', $programPublicId)->firstOrFail();

        $publishedCourseCount = Course::query()
            ->where('program_id', $program->id)
            ->where('is_published', true)
            ->count();
        if ($publishedCourseCount === 0) {
            abort(422, 'This program has no published courses available for enrollment.');
        }

        $existing = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('program_id', $program->id)
            ->whereNull('course_id')
            ->orderByDesc('id')
            ->first();

        if ($existing && in_array($existing->status, ['pending', 'approved', 'hold'], true)) {
            return response()->json([
                'message' => 'You already have an enrollment application for this program.',
            ], 409);
        }

        $stored = Storage::disk('public')->putFile('enrollment-payment-proofs', $uploaded);
        $proofPayload = [
            'payment_proof_path' => $stored,
            'payment_proof_original_name' => $uploaded->getClientOriginalName() ?: ('payment-'.$uploaded->hashName()),
            'payment_proof_mime' => $uploaded->getMimeType(),
        ];

        if ($existing && $existing->status === 'rejected') {
            if ($existing->payment_proof_path) {
                Storage::disk('public')->delete($existing->payment_proof_path);
            }

            $existing->update([
                'status' => 'pending',
                'submitted_at' => now()->toDateString(),
                ...$proofPayload,
            ]);
            $enrollment = $existing;
        } else {
            $enrollment = Enrollment::query()->create([
                'public_id' => 'enrollment-'.Str::lower(Str::ulid()),
                'user_id' => $user->id,
                'program_id' => $program->id,
                'course_id' => null,
                'status' => 'pending',
                'submitted_at' => now()->toDateString(),
                ...$proofPayload,
            ]);
        }

        $enrollment->load(['program', 'course']);

        LmsCatalogService::bustUserAnalyticsCache($user->id);
        $this->notifyManagersOfEnrollment($enrollment);

        return response()->json($this->formatStoreResponse($enrollment), 201);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatStoreResponse(Enrollment $enrollment): array
    {
        $payload = [
            'id' => $enrollment->public_id,
            'programId' => $enrollment->program?->public_id ?? '',
            'programTitle' => $enrollment->program?->title ?? '',
            'submittedAt' => optional($enrollment->submitted_at)->format('Y-m-d'),
            'status' => $enrollment->status,
            'hasPaymentProof' => true,
        ];

        if ($enrollment->course_id !== null && $enrollment->relationLoaded('course') && $enrollment->course !== null) {
            $payload['courseId'] = $enrollment->course->public_id;
            $payload['courseTitle'] = $enrollment->course->title ?? '';
        }

        return $payload;
    }

    public function show(string $publicId): JsonResponse
    {
        EnrollmentSchemaService::ensureFormDataColumns();
        EnrollmentSchemaService::ensureRejectionReasonColumn();

        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        $enrollment = Enrollment::query()
            ->where('public_id', $publicId)
            ->with(['program', 'course', 'user.studentProfile'])
            ->firstOrFail();

        if (! $this->canManageEnrollments($actor) && $enrollment->user_id !== $actor->id) {
            abort(403, 'You cannot view this enrollment.');
        }

        $student = $enrollment->user?->studentProfile;
        $documents = is_array($enrollment->documents) ? $enrollment->documents : [];
        $documentSummaries = [];

        foreach ($documents as $key => $doc) {
            if (! is_array($doc)) {
                continue;
            }
            $documentSummaries[$key] = [
                'originalName' => $doc['originalName'] ?? basename((string) ($doc['path'] ?? '')),
                'mime' => $doc['mime'] ?? null,
                'url' => '/api/enrollments/'.$enrollment->public_id.'/documents/'.$key,
            ];
        }

        $formData = $enrollment->form_data;

        return response()->json([
            'id' => $enrollment->public_id,
            'status' => $enrollment->status,
            'rejectionReason' => $enrollment->rejection_reason,
            'submittedAt' => optional($enrollment->submitted_at)->format('Y-m-d'),
            'programId' => $enrollment->program?->public_id ?? '',
            'programTitle' => $enrollment->program?->title ?? '',
            'courseId' => $enrollment->course?->public_id,
            'courseTitle' => $enrollment->course?->title ?? '',
            'userName' => $enrollment->user->name ?? '',
            'userEmail' => $enrollment->user->email ?? '',
            'phoneNumber' => $student?->phone_number ?? '',
            'schoolHeld' => $student?->school_held ?? '',
            'hasFormData' => is_array($formData) && $formData !== [],
            'formData' => is_array($formData) ? $formData : null,
            'documents' => $documentSummaries,
            'hasPaymentProof' => (bool) $enrollment->payment_proof_path,
            'paymentProofFileName' => $enrollment->payment_proof_original_name ?? null,
            'paymentProofUrl' => $enrollment->payment_proof_path
                ? '/api/enrollments/'.$enrollment->public_id.'/payment-proof'
                : null,
        ]);
    }

    private function isAllowedDocumentKey(string $documentKey): bool
    {
        if (in_array($documentKey, self::ENROLLMENT_DOCUMENT_KEYS, true)) {
            return true;
        }

        return str_starts_with($documentKey, 'partialPayment_');
    }

    public function storePartialPayment(Request $request, string $publicId): JsonResponse
    {
        EnrollmentSchemaService::ensureFormDataColumns();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'payment_proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        $enrollment = Enrollment::query()
            ->where('public_id', $publicId)
            ->with(['program', 'course'])
            ->firstOrFail();

        if (! $this->canManageEnrollments($actor) && $enrollment->user_id !== $actor->id) {
            abort(403, 'You cannot update this enrollment.');
        }

        if (! in_array((string) $enrollment->status, ['pending', 'approved', 'hold'], true)) {
            abort(422, 'Partial payments can only be submitted for active enrollment applications.');
        }

        $formData = is_array($enrollment->form_data) ? $enrollment->form_data : [];
        $partialPayments = is_array($formData['partialPayments'] ?? null) ? $formData['partialPayments'] : [];
        $requestedAmount = round((float) $data['amount'], 2);

        if ($requestedAmount <= 0) {
            abort(422, 'Amount must be greater than zero.');
        }

        $programFee = $enrollment->program?->enrollment_fee;
        if (is_numeric($programFee)) {
            $paymentSummary = EnrollmentPayments::summarizeFormDataPayments($formData);
            $paidSoFar = $paymentSummary['verifiedTotal'];
            $remaining = max(0.0, round(((float) $programFee) - $paidSoFar, 2));

            if ($remaining <= 0.009) {
                abort(422, 'This enrollment is already fully paid.');
            }

            if ($requestedAmount > $remaining + 0.009) {
                abort(422, 'Partial payment amount cannot exceed remaining balance (PHP '.number_format($remaining, 2).').');
            }
        }

        $uploaded = $request->file('payment_proof');
        $documentKey = 'partialPayment_'.Str::lower(Str::ulid());
        $stored = Storage::disk('public')->putFile('enrollment-documents', $uploaded);

        $entry = [
            'id' => 'pp-'.Str::lower(Str::ulid()),
            'amount' => $requestedAmount,
            'paidAt' => now()->toDateString(),
            'documentKey' => $documentKey,
            'originalName' => $uploaded->getClientOriginalName() ?: $uploaded->hashName(),
            'verificationStatus' => EnrollmentPayments::STATUS_PENDING,
        ];

        $partialPayments[] = $entry;
        $formData['partialPayments'] = $partialPayments;
        $formData['totalPartialPaid'] = EnrollmentPayments::summarizeFormDataPayments($formData)['verifiedTotal'];

        $documents = is_array($enrollment->documents) ? $enrollment->documents : [];
        $documents[$documentKey] = [
            'path' => $stored,
            'originalName' => $entry['originalName'],
            'mime' => $uploaded->getMimeType(),
        ];

        $enrollment->update([
            'form_data' => $formData,
            'documents' => $documents,
        ]);

        app(EnrollmentNotificationService::class)->notifyManagersOfPartialPayment(
            $enrollment->fresh(['program', 'course', 'user']),
            $requestedAmount
        );

        return response()->json([
            'enrollmentId' => $enrollment->public_id,
            'partialPayment' => $entry,
            'partialPayments' => $partialPayments,
            'totalPartialPaid' => $formData['totalPartialPaid'],
            'hasUnreviewedPayments' => true,
        ], 201);
    }

    public function verifyPayment(Request $request, string $publicId, string $paymentId): JsonResponse
    {
        EnrollmentSchemaService::ensureFormDataColumns();

        $data = $request->validate([
            'status' => ['required', 'string', 'in:correct,invalid'],
        ]);

        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        if (! $this->canManageEnrollments($actor)) {
            abort(403, 'You cannot verify enrollment payments.');
        }

        $enrollment = Enrollment::query()
            ->where('public_id', $publicId)
            ->with(['program', 'course', 'user'])
            ->firstOrFail();

        $formData = is_array($enrollment->form_data) ? $enrollment->form_data : [];
        $nextStatus = (string) $data['status'];

        if ($paymentId === 'initial-downpayment') {
            $downpayment = EnrollmentPayments::parseMoneyAmount($formData['downpaymentAmount'] ?? null);
            if ($downpayment <= 0) {
                abort(404, 'Initial downpayment not found.');
            }
            $formData['downpaymentVerificationStatus'] = $nextStatus;
        } else {
            $partialPayments = is_array($formData['partialPayments'] ?? null) ? $formData['partialPayments'] : [];
            $matched = false;
            foreach ($partialPayments as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                if ((string) ($entry['id'] ?? '') !== $paymentId) {
                    continue;
                }
                $partialPayments[$index]['verificationStatus'] = $nextStatus;
                $matched = true;
                break;
            }
            if (! $matched) {
                abort(404, 'Payment not found.');
            }
            $formData['partialPayments'] = $partialPayments;
        }

        $summary = EnrollmentPayments::summarizeFormDataPayments($formData);
        $formData['totalPartialPaid'] = $summary['verifiedTotal'];

        $enrollment->update(['form_data' => $formData]);

        LmsCatalogService::bustUserAnalyticsCache($enrollment->user_id);

        return response()->json([
            'enrollmentId' => $enrollment->public_id,
            'paymentId' => $paymentId,
            'verificationStatus' => $nextStatus,
            'totalPaid' => $summary['verifiedTotal'],
            'hasUnreviewedPayments' => $summary['hasUnreviewed'],
        ]);
    }

    private function parseMoneyAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return max(0.0, round((float) $value, 2));
        }

        $cleaned = preg_replace('/[^\d.]/', '', (string) $value);
        if (! is_string($cleaned) || $cleaned === '') {
            return 0.0;
        }

        $parsed = (float) $cleaned;

        return $parsed > 0 ? round($parsed, 2) : 0.0;
    }

    public function downloadDocument(string $publicId, string $documentKey): StreamedResponse|JsonResponse
    {
        if (! $this->isAllowedDocumentKey($documentKey)) {
            abort(404, 'Document not found.');
        }

        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        $enrollment = Enrollment::query()->where('public_id', $publicId)->firstOrFail();

        if (! $this->canManageEnrollments($actor) && $enrollment->user_id !== $actor->id) {
            abort(403, 'You cannot view this document.');
        }

        $documents = is_array($enrollment->documents) ? $enrollment->documents : [];
        $doc = $documents[$documentKey] ?? null;

        if (! is_array($doc) || empty($doc['path'])) {
            abort(404, 'Document not found.');
        }

        $path = (string) $doc['path'];
        if (! Storage::disk('public')->exists($path)) {
            abort(404, 'Document not found.');
        }

        $disk = Storage::disk('public');
        $name = $doc['originalName'] ?? basename($path);
        $mime = $doc['mime'] ?? null;

        if ($mime) {
            return $disk->response($path, $name, ['Content-Type' => $mime]);
        }

        return $disk->download($path, $name);
    }

    public function downloadPaymentProof(string $publicId): StreamedResponse|JsonResponse
    {
        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        $enrollment = Enrollment::query()->where('public_id', $publicId)->firstOrFail();

        if (! $this->canManageEnrollments($actor) && $enrollment->user_id !== $actor->id) {
            abort(403, 'You cannot view this payment proof.');
        }

        $path = $enrollment->payment_proof_path;
        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404, 'Payment proof not found.');
        }

        $disk = Storage::disk('public');
        $name = $enrollment->payment_proof_original_name ?: 'payment-proof';
        $mime = $enrollment->payment_proof_mime;

        if ($mime) {
            return $disk->response($path, $name, ['Content-Type' => $mime]);
        }

        return $disk->download($path, $name);
    }

    public function updateStatus(Request $request, string $publicId): JsonResponse
    {
        EnrollmentSchemaService::ensureRejectionReasonColumn();

        $data = $request->validate([
            'status' => 'required|string|in:pending,approved,rejected,hold',
            'rejection_reason' => 'nullable|string|max:2000',
        ]);

        $actor = $this->lmsActor();
        if ($actor->id <= 0) {
            abort(401, 'Authentication required.');
        }

        $enrollment = Enrollment::query()->where('public_id', $publicId)->firstOrFail();

        if (! $this->canManageEnrollments($actor) && $enrollment->user_id !== $actor->id) {
            abort(403, 'You cannot update this enrollment.');
        }

        if ($data['status'] === 'rejected' && $this->canManageEnrollments($actor)) {
            $reason = trim((string) ($data['rejection_reason'] ?? ''));
            if ($reason === '') {
                abort(422, 'A rejection reason is required.');
            }
            $data['rejection_reason'] = $reason;
        }

        $previousStatus = (string) $enrollment->status;
        $updatePayload = ['status' => $data['status']];

        if ($data['status'] === 'rejected') {
            $updatePayload['rejection_reason'] = trim((string) ($data['rejection_reason'] ?? ''));
        } else {
            $updatePayload['rejection_reason'] = null;
        }

        $enrollment->update($updatePayload);

        if ($this->canManageEnrollments($actor)) {
            app(EnrollmentNotificationService::class)->notifyStudentOfStatusChange(
                $enrollment->fresh(['program', 'course', 'user']),
                $previousStatus,
                (string) $data['status'],
                $updatePayload['rejection_reason'] ?? null
            );
        }

        LmsCatalogService::bustUserAnalyticsCache($enrollment->user_id);

        return response()->json([
            'enrollmentId' => $enrollment->public_id,
            'status' => $enrollment->status,
        ]);
    }

    protected function notifyManagersOfEnrollment(Enrollment $enrollment): void
    {
        app(EnrollmentNotificationService::class)->notifyManagersOfNewApplication($enrollment);
    }

    protected function canManageEnrollments(User $user): bool
    {
        if ($user->id <= 0) {
            return false;
        }

        $role = strtolower(trim((string) ($user->role ?? '')));

        return $role === 'instructor' || $role === 'admin';
    }
}
