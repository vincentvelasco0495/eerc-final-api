<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\InAppNotification;
use App\Models\User;
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
        $targetLabel = $courseTitle !== '' ? "{$courseTitle} · {$programTitle}" : $programTitle;

        $title = 'New enrollment application';
        $body = "{$studentName} submitted an enrollment application for {$targetLabel}. Review it in the enrollments dashboard.";

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
            $title = 'Enrollment approved';
            $body = "Your enrollment application for {$targetLabel} has been approved. You can now access your learning materials.";
        } elseif ($newStatus === 'hold') {
            $kind = 'enrollment_on_hold';
            $title = 'Enrollment on hold';
            $body = "Your enrollment for {$targetLabel} has been placed on hold. Course access is paused until an administrator approves it again.";
        } else {
            $kind = 'enrollment_rejected';
            $title = 'Enrollment not approved';
            $reason = trim((string) ($rejectionReason ?? $enrollment->rejection_reason ?? ''));
            $body = "Your enrollment application for {$targetLabel} was not approved.";
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
    }

    public function shouldRemoveOnRead(?string $kind): bool
    {
        return in_array((string) $kind, self::REMOVE_ON_READ_KINDS, true);
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
