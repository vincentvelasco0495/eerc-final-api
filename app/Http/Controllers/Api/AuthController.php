<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LmsUserProfile;
use App\Models\Student;
use App\Models\User;
use App\Services\LmsCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::query()->create([
            'public_uid' => 'user-'.Str::lower(Str::ulid()),
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'student',
        ]);

        LmsUserProfile::query()->create([
            'user_id' => $user->id,
            'watermark_name' => $user->name,
            'joined_at' => now()->toDateString(),
        ]);

        Student::query()->create(['user_id' => $user->id]);

        $user->refresh();
        $user->load(['lmsProfile.program', 'badges', 'studentProfile', 'instructorProfile']);

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'user' => $catalog->userPayload($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user->load(['lmsProfile.program', 'badges', 'studentProfile', 'instructorProfile']);

        // Single active session: revoke tokens from other devices before issuing a new one.
        $user->tokens()->delete();

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'user' => $catalog->userPayload($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
