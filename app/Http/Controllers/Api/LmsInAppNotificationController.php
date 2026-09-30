<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\InAppNotification;
use App\Services\EnrollmentNotificationService;
use App\Services\NotificationSchemaService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LmsInAppNotificationController extends Controller
{
    use ResolvesLmsActor;

    public function index(): JsonResponse
    {
        NotificationSchemaService::ensureEnrollmentNotificationColumns();

        $user = $this->lmsActor();
        if ($user->id <= 0) {
            abort(Response::HTTP_UNAUTHORIZED, 'Authentication required.');
        }

        $items = InAppNotification::query()
            ->where('user_id', $user->id)
            ->with([
                'announcement:id,public_id,title,body',
                'enrollment:id,public_id,rejection_reason',
            ])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $data = $items->map(fn (InAppNotification $n) => $this->toDrawerPayload($n))->values();

        return response()->json(['data' => $data]);
    }

    public function markRead(string $publicId): JsonResponse
    {
        $user = $this->lmsActor();
        if ($user->id <= 0) {
            abort(Response::HTTP_UNAUTHORIZED, 'Authentication required.');
        }

        $notification = InAppNotification::query()
            ->where('public_id', $publicId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $service = app(EnrollmentNotificationService::class);
        if ($service->shouldRemoveOnRead($notification->kind)) {
            $notification->delete();

            return response()->json(['ok' => true, 'isUnRead' => false, 'removed' => true]);
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true, 'isUnRead' => false, 'removed' => false]);
    }

    public function markUnread(string $publicId): JsonResponse
    {
        $user = $this->lmsActor();
        if ($user->id <= 0) {
            abort(Response::HTTP_UNAUTHORIZED, 'Authentication required.');
        }

        $notification = InAppNotification::query()
            ->where('public_id', $publicId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if (app(EnrollmentNotificationService::class)->shouldRemoveOnRead($notification->kind)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'This notification cannot be marked unread.');
        }

        $notification->update(['read_at' => null]);

        return response()->json(['ok' => true, 'isUnRead' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        $user = $this->lmsActor();
        if ($user->id <= 0) {
            abort(Response::HTTP_UNAUTHORIZED, 'Authentication required.');
        }

        InAppNotification::query()
            ->where('user_id', $user->id)
            ->whereIn('kind', EnrollmentNotificationService::REMOVE_ON_READ_KINDS)
            ->delete();

        InAppNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->where(function ($query) {
                $query->whereNull('kind')
                    ->orWhereNotIn('kind', EnrollmentNotificationService::REMOVE_ON_READ_KINDS);
            })
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function toDrawerPayload(InAppNotification $notification): array
    {
        $kind = (string) ($notification->kind ?? 'announcement');

        if ($kind === 'announcement' && $notification->announcement_id) {
            $title = e((string) ($notification->announcement?->title ?? 'Announcement'));
            $titleHtml = "<p><strong>{$title}</strong></p>";
            $body = (string) ($notification->announcement?->body ?? '');

            return [
                'id' => $notification->public_id,
                'type' => 'mail',
                'category' => 'Announcement',
                'notificationKind' => $kind,
                'removeOnRead' => false,
                'isUnRead' => $notification->read_at === null,
                'createdAt' => $notification->created_at?->toIso8601String(),
                'title' => $titleHtml,
                'message' => $body,
                'announcementId' => $notification->announcement?->public_id,
            ];
        }

        $title = e((string) ($notification->title ?? 'Notification'));
        $titleHtml = "<p><strong>{$title}</strong></p>";
        $body = (string) ($notification->body ?? '');

        $type = match ($kind) {
            'enrollment_approved' => 'delivery',
            'enrollment_rejected' => 'mail',
            default => 'order',
        };

        $rejectionReason = $kind === 'enrollment_rejected'
            ? trim((string) ($notification->enrollment?->rejection_reason ?? ''))
            : '';

        return [
            'id' => $notification->public_id,
            'type' => $type,
            'category' => 'Enrollment',
            'notificationKind' => $kind,
            'removeOnRead' => app(EnrollmentNotificationService::class)->shouldRemoveOnRead($kind),
            'isUnRead' => $notification->read_at === null,
            'createdAt' => $notification->created_at?->toIso8601String(),
            'title' => $titleHtml,
            'message' => $body,
            'enrollmentId' => $notification->enrollment?->public_id,
            'rejectionReason' => $rejectionReason,
        ];
    }
}
