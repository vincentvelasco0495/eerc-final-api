<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Enrollment;
use App\Models\InAppNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnnouncementDispatchService
{
    /**
     * Notify every user with at least one approved program enrollment (excluding the author).
     *
     * @return int Number of notification rows created
     */
    public function fanOutToEnrolledStudents(Announcement $announcement, int $authorId): int
    {
        $recipientIds = Enrollment::query()
            ->approved()
            ->where('user_id', '!=', $authorId)
            ->distinct()
            ->pluck('user_id')
            ->values();

        if ($recipientIds->isEmpty()) {
            return 0;
        }

        $now = now();
        $chunks = $recipientIds->chunk(500);
        $total = 0;

        foreach ($chunks as $chunk) {
            $rows = [];
            foreach ($chunk as $userId) {
                $rows[] = [
                    'public_id' => 'notif-'.Str::lower(Str::ulid()),
                    'user_id' => $userId,
                    'announcement_id' => $announcement->id,
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('in_app_notifications')->insert($rows);
            $total += count($rows);
        }

        return $total;
    }
}
