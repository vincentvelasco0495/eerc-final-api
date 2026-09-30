<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\User;
use App\Services\LmsInstructorService;
use App\Support\PageAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LmsInstructorController extends Controller
{
    public function linkableUsers(): JsonResponse
    {
        $assignedIds = Instructor::query()->pluck('user_id');

        $users = User::query()
            ->whereNotIn('id', $assignedIds)
            ->orderBy('name')
            ->limit(200)
            ->get(['public_uid', 'name', 'email', 'role']);

        $data = $users->map(fn (User $u) => [
            'publicUid' => $u->public_uid,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
        ])->values()->all();

        return response()->json(['data' => $data]);
    }

    public function index(Request $request, LmsInstructorService $service): JsonResponse
    {
        if (! $request->filled('page')) {
            return response()->json(['data' => $service->instructors()]);
        }

        if ($response = PageAuthorization::denyUnlessCanAccess($request, '/setting-instructor')) {
            return $response;
        }

        $validated = $request->validate([
            'page' => ['required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) $validated['page'];
        $search = isset($validated['search']) ? (string) $validated['search'] : null;

        return response()->json($service->instructorsPaginated($page, $perPage, $search));
    }

    public function store(Request $request, LmsInstructorService $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
            'achievements' => ['nullable', 'string', 'max:65535'],
            'profilePath' => ['nullable', 'string', 'max:2048'],
            'profileImage' => ['sometimes', 'nullable', 'image', 'max:4096'],
        ]);

        $email = strtolower(trim($validated['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user) {
            if (Instructor::withTrashed()->where('user_id', $user->id)->exists()) {
                return response()->json([
                    'message' => 'An instructor profile already exists for this email.',
                ], 422);
            }
            $user->name = trim((string) $validated['name']);
            $user->save();
        } else {
            $user = User::query()->create([
                'public_uid' => 'user-'.Str::lower(Str::ulid()),
                'name' => trim((string) $validated['name']),
                'email' => $email,
                'password' => Hash::make(Str::random(32)),
                'role' => 'instructor',
                'status' => 'active',
            ]);
        }

        $profilePath = isset($validated['profilePath']) && trim((string) $validated['profilePath']) !== ''
            ? trim((string) $validated['profilePath'])
            : null;
        if ($request->hasFile('profileImage')) {
            $profilePath = $this->storeProfileImage($request);
        }

        if (array_key_exists('status', $validated)) {
            $user->status = (string) $validated['status'];
            $user->save();
        }

        $instructor = Instructor::query()->create([
            'user_id' => $user->id,
            'achievements' => isset($validated['achievements']) ? trim((string) $validated['achievements']) : null,
            'profile_path' => $profilePath,
        ]);

        return response()->json(['data' => $service->formatInstructor($instructor->load('user'))], 201);
    }

    public function update(Request $request, string $userPublicUid, LmsInstructorService $service): JsonResponse
    {
        $instructor = $this->findInstructorByUserPublicUid($userPublicUid);

        $user = $instructor->user;

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'status' => ['sometimes', 'in:active,inactive'],
            'achievements' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'profilePath' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'profileImage' => ['sometimes', 'nullable', 'image', 'max:4096'],
        ]);

        if (array_key_exists('name', $validated)) {
            $user->name = trim((string) $validated['name']);
        }
        if (array_key_exists('email', $validated)) {
            $user->email = strtolower(trim((string) $validated['email']));
        }
        if (array_key_exists('status', $validated)) {
            $user->status = (string) $validated['status'];
        }
        $user->save();
        if (array_key_exists('achievements', $validated)) {
            $instructor->achievements = $validated['achievements'] !== null
                ? trim((string) $validated['achievements'])
                : null;
        }
        if (array_key_exists('profilePath', $validated)) {
            $instructor->profile_path = $validated['profilePath'] !== null && trim((string) $validated['profilePath']) !== ''
                ? trim((string) $validated['profilePath'])
                : null;
        }
        if ($request->hasFile('profileImage')) {
            if (is_string($instructor->profile_path) && $instructor->profile_path !== '' && ! str_starts_with($instructor->profile_path, 'http')) {
                Storage::disk('public')->delete($instructor->profile_path);
            }
            $instructor->profile_path = $this->storeProfileImage($request);
        }

        $instructor->save();

        return response()->json(['data' => $service->formatInstructor($instructor->fresh()->load('user'))]);
    }

    public function destroy(string $userPublicUid): JsonResponse
    {
        $instructor = $this->findInstructorByUserPublicUid($userPublicUid);

        if (is_string($instructor->profile_path) && $instructor->profile_path !== '' && ! str_starts_with($instructor->profile_path, 'http')) {
            Storage::disk('public')->delete($instructor->profile_path);
        }

        $instructor->delete();

        return response()->json(['message' => 'Instructor deleted.']);
    }

    private function findInstructorByUserPublicUid(string $userPublicUid): Instructor
    {
        $user = User::query()->where('public_uid', $userPublicUid)->firstOrFail();

        return Instructor::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function storeProfileImage(Request $request): ?string
    {
        $file = $request->file('profileImage');
        if (! $file) {
            return null;
        }

        return Storage::disk('public')->putFile('instructor-profiles', $file);
    }
}
