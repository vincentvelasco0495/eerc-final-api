<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\AnnouncementDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LmsAnnouncementController extends Controller
{
    use ResolvesLmsActor;

    public function store(Request $request, AnnouncementDispatchService $dispatch): JsonResponse
    {
        $user = $this->lmsActor();
        if ($user->id <= 0) {
            abort(Response::HTTP_UNAUTHORIZED, 'Authentication required.');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:200000'],
        ]);

        $announcement = Announcement::query()->create([
            'public_id' => 'ann-'.Str::lower(Str::ulid()),
            'author_id' => $user->id,
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
        ]);

        $recipientCount = $dispatch->fanOutToEnrolledStudents($announcement, $user->id);

        return response()->json([
            'id' => $announcement->public_id,
            'title' => $announcement->title,
            'recipientCount' => $recipientCount,
        ], Response::HTTP_CREATED);
    }
}
