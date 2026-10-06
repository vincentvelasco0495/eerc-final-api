<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\LmsUserProfile;
use App\Models\Student;
use App\Services\LmsCatalogService;
use App\Services\LmsStudentService;
use App\Support\StudentProfileValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class LmsUserController extends Controller
{
    use ResolvesLmsActor;

    public function show(LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor()->load([
            'lmsProfile.program',
            'badges',
            'studentProfile',
            'instructorProfile',
        ]);

        return response()->json($catalog->userPayload($user));
    }

    public function update(Request $request, LmsCatalogService $catalog, LmsStudentService $students): JsonResponse
    {
        $user = $this->lmsActor();
        if ($user->id <= 0) {
            abort(401, 'Authentication required.');
        }

        $isStudent = strtolower((string) $user->role) === 'student';

        $rules = [
            'firstName' => ['sometimes', 'string', 'max:120'],
            'lastName' => ['sometimes', 'nullable', 'string', 'max:120'],
            'schoolHeld' => ['sometimes', 'nullable', 'string', 'max:255'],
            'watermarkName' => ['sometimes', 'nullable', 'string', 'max:120'],
            'aliasName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'displayName' => ['sometimes', 'nullable', 'string', 'max:120'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'facebook' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'linkedin' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'twitter' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'instagram' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'profileImage' => ['sometimes', 'nullable', 'image', 'max:4096'],
            'coverImage' => ['sometimes', 'nullable', 'image', 'max:8192'],
            'password' => ['sometimes', 'nullable', 'confirmed', Password::defaults()],
        ];
        if ($isStudent) {
            $rules['phoneNumber'] = ['required', 'string', 'max:32'];
            $rules['birthday'] = ['required', 'date'];
        } else {
            $rules['phoneNumber'] = ['sometimes', 'nullable', 'string', 'max:32'];
            $rules['birthday'] = ['sometimes', 'nullable', 'date'];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($isStudent) {
            StudentProfileValidation::applyToValidator($validator);
        }

        $validated = $validator->validate();

        if (array_key_exists('firstName', $validated) || array_key_exists('lastName', $validated)) {
            $first = array_key_exists('firstName', $validated)
                ? trim((string) $validated['firstName'])
                : null;
            $last = array_key_exists('lastName', $validated)
                ? trim((string) $validated['lastName'])
                : null;

            if ($first !== null) {
                $parts = preg_split('/\s+/', trim($user->name)) ?: [];
                $existingLast = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
                $resolvedFirst = $first;
                $resolvedLast = $last ?? $existingLast;
                $user->name = trim($resolvedFirst.' '.$resolvedLast);
            } elseif ($last !== null) {
                $parts = preg_split('/\s+/', trim($user->name)) ?: [];
                $resolvedFirst = $parts[0] ?? '';
                $user->name = trim($resolvedFirst.' '.$last);
            }
        }

        if (! empty($validated['password'])) {
            $user->password = Hash::make((string) $validated['password']);
        }

        $user->save();

        if (array_key_exists('watermarkName', $validated) || array_key_exists('displayName', $validated)) {
            $profile = LmsUserProfile::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'watermark_name' => $user->name,
                    'joined_at' => now()->toDateString(),
                ]
            );
            $nextWatermark = array_key_exists('watermarkName', $validated)
                ? trim((string) ($validated['watermarkName'] ?? ''))
                : trim((string) ($validated['displayName'] ?? ''));
            $profile->watermark_name = $nextWatermark !== '' ? $nextWatermark : $user->name;
            $profile->save();
        }

        if ($isStudent) {
            Student::query()->firstOrCreate(['user_id' => $user->id]);
            $studentFields = [];
            foreach (['phoneNumber', 'birthday', 'schoolHeld', 'aliasName'] as $key) {
                if (array_key_exists($key, $validated)) {
                    $studentFields[$key] = $validated[$key];
                }
            }
            if ($studentFields !== []) {
                $students->updateProfileForUser($user, $studentFields);
            }
        } else {
            $this->syncInstructorProfile($user, $validated, $request);
        }

        $user->refresh();
        $user->load([
            'lmsProfile.program',
            'badges',
            'studentProfile',
            'instructorProfile',
        ]);

        return response()->json($catalog->userPayload($user));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function syncInstructorProfile($user, array $validated, Request $request): void
    {
        $instructor = Instructor::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['achievements' => null]
        );

        $textMap = [
            'displayName' => 'display_name',
            'position' => 'position',
            'bio' => 'bio',
            'facebook' => 'facebook',
            'linkedin' => 'linkedin',
            'twitter' => 'twitter',
            'instagram' => 'instagram',
        ];

        foreach ($textMap as $requestKey => $column) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }
            $value = trim((string) ($validated[$requestKey] ?? ''));
            $instructor->{$column} = $value !== '' ? $value : null;
        }

        $profileImage = $request->file('profileImage');
        if ($profileImage instanceof UploadedFile) {
            $this->replaceStoredPath($instructor->profile_path);
            $instructor->profile_path = $profileImage->store('instructor-profiles', 'public');
        }

        $coverImage = $request->file('coverImage');
        if ($coverImage instanceof UploadedFile) {
            $this->replaceStoredPath($instructor->cover_path);
            $instructor->cover_path = $coverImage->store('instructor-covers', 'public');
        }

        $instructor->save();
    }

    protected function replaceStoredPath(?string $path): void
    {
        $normalized = is_string($path) ? trim($path) : '';
        if ($normalized === '' || str_starts_with($normalized, 'http')) {
            return;
        }
        Storage::disk('public')->delete($normalized);
    }
}
