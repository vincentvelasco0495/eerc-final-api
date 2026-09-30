<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Services\LmsStudentService;
use App\Support\StudentProfileValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LmsStudentController extends Controller
{
    public function linkableUsers(): JsonResponse
    {
        $assignedIds = Student::query()->pluck('user_id');

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

    public function index(Request $request, LmsStudentService $service): JsonResponse
    {
        if (! $request->filled('page')) {
            return response()->json(['data' => $service->students()]);
        }

        $validated = $request->validate([
            'page' => ['required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) $validated['page'];
        $search = isset($validated['search']) ? (string) $validated['search'] : null;

        return response()->json($service->studentsPaginated($page, $perPage, $search));
    }

    public function store(Request $request, LmsStudentService $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
            'notes' => ['nullable', 'string', 'max:65535'],
            'profilePath' => ['nullable', 'string', 'max:2048'],
            'profileImage' => ['sometimes', 'nullable', 'image', 'max:4096'],
        ]);

        $email = strtolower(trim($validated['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user) {
            if (Student::withTrashed()->where('user_id', $user->id)->exists()) {
                return response()->json([
                    'message' => 'A student roster entry already exists for this email.',
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
                'role' => 'student',
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

        $student = Student::query()->create([
            'user_id' => $user->id,
            'notes' => isset($validated['notes']) ? trim((string) $validated['notes']) : null,
            'profile_path' => $profilePath,
        ]);

        return response()->json(['data' => $service->formatStudent($student->load('user'))], 201);
    }

    public function update(Request $request, string $userPublicUid, LmsStudentService $service): JsonResponse
    {
        $student = $this->findStudentByUserPublicUid($userPublicUid);

        $user = $student->user;

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'status' => ['sometimes', 'in:active,inactive'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'profilePath' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'profileImage' => ['sometimes', 'nullable', 'image', 'max:4096'],
            'phoneNumber' => ['sometimes', 'nullable', 'string', 'max:32'],
            'birthday' => ['sometimes', 'nullable', 'date'],
            'schoolHeld' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        StudentProfileValidation::applyToValidator($validator, false);

        $validated = $validator->validate();

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
        if (array_key_exists('notes', $validated)) {
            $student->notes = $validated['notes'] !== null
                ? trim((string) $validated['notes'])
                : null;
        }
        if (array_key_exists('profilePath', $validated)) {
            $student->profile_path = $validated['profilePath'] !== null && trim((string) $validated['profilePath']) !== ''
                ? trim((string) $validated['profilePath'])
                : null;
        }
        if ($request->hasFile('profileImage')) {
            if (is_string($student->profile_path) && $student->profile_path !== '' && ! str_starts_with($student->profile_path, 'http')) {
                Storage::disk('public')->delete($student->profile_path);
            }
            $student->profile_path = $this->storeProfileImage($request);
        }
        if (array_key_exists('phoneNumber', $validated)) {
            $student->phone_number = $validated['phoneNumber'] !== null && trim((string) $validated['phoneNumber']) !== ''
                ? trim((string) $validated['phoneNumber'])
                : null;
        }
        if (array_key_exists('birthday', $validated)) {
            $student->birthday = $validated['birthday'] !== null && trim((string) $validated['birthday']) !== ''
                ? trim((string) $validated['birthday'])
                : null;
        }
        if (array_key_exists('schoolHeld', $validated)) {
            $student->school_held = $validated['schoolHeld'] !== null && trim((string) $validated['schoolHeld']) !== ''
                ? trim((string) $validated['schoolHeld'])
                : null;
        }

        $student->save();

        return response()->json(['data' => $service->formatStudent($student->fresh()->load('user'))]);
    }

    public function destroy(string $userPublicUid): JsonResponse
    {
        $student = $this->findStudentByUserPublicUid($userPublicUid);

        if (is_string($student->profile_path) && $student->profile_path !== '' && ! str_starts_with($student->profile_path, 'http')) {
            Storage::disk('public')->delete($student->profile_path);
        }

        $student->delete();

        return response()->json(['message' => 'Student roster entry deleted.']);
    }

    private function findStudentByUserPublicUid(string $userPublicUid): Student
    {
        $user = User::query()->where('public_uid', $userPublicUid)->firstOrFail();

        return Student::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function storeProfileImage(Request $request): ?string
    {
        $file = $request->file('profileImage');
        if (! $file) {
            return null;
        }

        return Storage::disk('public')->putFile('student-profiles', $file);
    }
}
