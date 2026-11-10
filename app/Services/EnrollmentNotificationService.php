<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\InAppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EnrollmentNotificationService
{
    public const REMOVE_ON_READ_KINDS = [
        'enrollment_approved',
        'enrollment_rejected',
        'enrollment_on_hold',
    ];

    public function notifyManagersOfNewApplication(Enrollment $enrollment): void
    {
        NotificationSchemaService::ensureEnrollmentNotificationColumns();

        $enrollment->loadMissing(['program', 'course', 'user']);

        $studentName = trim((string) ($enrollment->user?->name ?? 'A student'));
        $programTitle = trim((string) ($enrollment->program?->title ?? 'a program'));
        $courseTitle = trim((string) ($enrollment->course?->title ?? ''));
        $isCourseAccess = $enrollment->course_id !== null;
        $targetLabel = $courseTitle !== '' ? "{$courseTitle} · {$programTitle}" : $programTitle;

        $title = $isCourseAccess ? 'New course access request' : 'New enrollment application';
        $body = $isCourseAccess
            ? "{$studentName} requested access to {$targetLabel}. Review it in the enrollments dashboard."
            : "{$studentName} submitted an enrollment application for {$targetLabel}. Review it in the enrollments dashboard.";

        $managerIds = User::query()
            ->whereIn('role', ['admin', 'instructor'])
            ->pluck('id');

        foreach ($managerIds as $userId) {
            if ((int) $userId === (int) $enrollment->user_id) {
                continue;
            }

            $this->createNotification(
                (int) $userId,
                'enrollment_submitted',
                $title,
                $body,
                (int) $enrollment->id
            );
        }
    }

    public function notifyManagersOfPartialPayment(Enrollment $enrollment, float $amount): void
    {
        NotificationSchemaService::ensureEnrollmentNotificationColumns();

        $enrollment->loadMissing(['program', 'course', 'user']);

        $studentName = trim((string) ($enrollment->user?->name ?? 'A student'));
        $programTitle = trim((string) ($enrollment->program?->title ?? 'a program'));
        $courseTitle = trim((string) ($enrollment->course?->title ?? ''));
        $targetLabel = $courseTitle !== '' ? "{$courseTitle} · {$programTitle}" : $programTitle;
        $amountLabel = number_format(max(0, $amount), 2);

        $title = 'New partial payment submitted';
        $body = "{$studentName} submitted a partial payment of PHP {$amountLabel} for {$targetLabel}. Review it in the enrollments dashboard.";

        $managerIds = User::query()
            ->whereIn('role', ['admin', 'instructor'])
            ->pluck('id');

        foreach ($managerIds as $userId) {
            if ((int) $userId === (int) $enrollment->user_id) {
                continue;
            }

            $this->createNotification(
                (int) $userId,
                'enrollment_partial_payment_submitted',
                $title,
                $body,
                (int) $enrollment->id
            );
        }
    }

    public function notifyStudentOfStatusChange(
        Enrollment $enrollment,
        string $previousStatus,
        string $newStatus,
        ?string $rejectionReason = null
    ): void {
        if (! in_array($newStatus, ['approved', 'rejected', 'hold'], true)) {
            return;
        }

        if ($previousStatus === $newStatus) {
            return;
        }

        NotificationSchemaService::ensureEnrollmentNotificationColumns();

        $enrollment->loadMissing(['program', 'course', 'user']);

        $programTitle = trim((string) ($enrollment->program?->title ?? 'your program'));
        $courseTitle = trim((string) ($enrollment->course?->title ?? ''));
        $targetLabel = $courseTitle !== '' ? "{$courseTitle} · {$programTitle}" : $programTitle;

        if ($newStatus === 'approved') {
            $kind = 'enrollment_approved';
            $title = $courseTitle !== '' ? 'Course access approved' : 'Enrollment approved';
            $body = $courseTitle !== ''
                ? "Your access request for {$targetLabel} has been approved. You can now open this course."
                : "Your enrollment application for {$targetLabel} has been approved. You can now request access to its courses.";
        } elseif ($newStatus === 'hold') {
            $kind = 'enrollment_on_hold';
            $title = $courseTitle !== '' ? 'Course access on hold' : 'Enrollment on hold';
            $body = $courseTitle !== ''
                ? "Your access to {$targetLabel} has been placed on hold. Course materials stay locked until an administrator approves it again."
                : "Your enrollment for {$targetLabel} has been placed on hold. Course access is paused until an administrator approves it again.";
        } else {
            $kind = 'enrollment_rejected';
            $title = $courseTitle !== '' ? 'Course access not approved' : 'Enrollment not approved';
            $reason = trim((string) ($rejectionReason ?? $enrollment->rejection_reason ?? ''));
            $body = $courseTitle !== ''
                ? "Your access request for {$targetLabel} was not approved."
                : "Your enrollment application for {$targetLabel} was not approved.";
            if ($reason !== '') {
                $body .= "\n\nReason:\n{$reason}";
            } else {
                $body .= ' Contact EERC Learning Center if you have questions.';
            }
        }

        $this->createNotification(
            (int) $enrollment->user_id,
            $kind,
            $title,
            $body,
            (int) $enrollment->id
        );

        if ($newStatus === 'approved') {
            $this->emailApproval($enrollment->user, $title, $body);
        }
    }

    public function shouldRemoveOnRead(?string $kind): bool
    {
        return in_array((string) $kind, self::REMOVE_ON_READ_KINDS, true);
    }

    private function emailApproval(?User $user, string $title, string $body): void
    {
        $email = trim((string) ($user?->email ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        if ((string) config('mail.default') !== 'smtp' || trim((string) config('mail.mailers.smtp.host')) === '') {
            return;
        }

        $site = (string) config('lms.frontend_url', 'https://eerclearningcenter.com');
        $messageBody = $body."\n\nSign in at {$site} to continue.";

        try {
            Mail::raw($messageBody, function ($message) use ($email, $title) {
                $message->to($email)->subject($title);
            });
        } catch (\Throwable $exception) {
            Log::warning('Enrollment approval email failed.', [
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function createNotification(
        int $userId,
        string $kind,
        string $title,
        string $body,
        int $enrollmentId
    ): void {
        if ($userId <= 0) {
            return;
        }

        InAppNotification::query()->create([
            'public_id' => 'notif-'.Str::lower(Str::ulid()),
            'user_id' => $userId,
            'announcement_id' => null,
            'enrollment_id' => $enrollmentId,
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
        ]);
    }
}
