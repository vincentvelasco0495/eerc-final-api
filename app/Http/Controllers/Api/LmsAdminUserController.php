<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LmsAdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class LmsAdminUserController extends Controller
{
    public function index(Request $request, LmsAdminUserService $admins): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $query = User::query()
            ->whereRaw('LOWER(role) = ?', ['admin'])
            ->orderBy('name');

        $term = isset($validated['search']) ? trim((string) $validated['search']) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
            });
        }

        $rows = $query->get()->map(fn (User $user) => $admins->format($user))->values()->all();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, LmsAdminUserService $admins): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
        ]);

        $user = $admins->create(
            trim($data['name']),
            strtolower(trim($data['email'])),
            $data['password'],
            (string) ($data['status'] ?? 'active')
        );

        return response()->json(['data' => $admins->format($user)], 201);
    }

    public function update(Request $request, string $userPublicUid, LmsAdminUserService $admins): JsonResponse
    {
        $user = $this->findAdmin($userPublicUid);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
            'password' => ['sometimes', 'nullable', 'confirmed', Password::defaults()],
        ]);

        if (array_key_exists('name', $data)) {
            $user->name = trim($data['name']);
        }
        if (array_key_exists('email', $data)) {
            $user->email = strtolower(trim($data['email']));
        }
        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $user->status = $data['status'];
        }
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        if ($user->lmsProfile) {
            $user->lmsProfile->watermark_name = $user->name;
            $user->lmsProfile->save();
        }

        return response()->json(['data' => $admins->format($user->fresh())]);
    }

    public function resetPassword(Request $request, string $userPublicUid): JsonResponse
    {
        $user = $this->findAdmin($userPublicUid);

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->password = $validated['password'];
        $user->save();

        return response()->json(['message' => 'Password reset.']);
    }

    public function destroy(Request $request, string $userPublicUid): JsonResponse
    {
        $user = $this->findAdmin($userPublicUid);

        if ((int) $request->user()?->id === (int) $user->id) {
            return response()->json(['message' => 'You cannot delete your own admin account.'], 422);
        }

        $remaining = User::query()
            ->whereRaw('LOWER(role) = ?', ['admin'])
            ->where('id', '!=', $user->id)
            ->count();

        if ($remaining < 1) {
            return response()->json(['message' => 'At least one admin account must remain.'], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Admin account deleted.']);
    }

    private function findAdmin(string $userPublicUid): User
    {
        $user = User::query()->where('public_uid', $userPublicUid)->firstOrFail();
        if (strtolower((string) $user->role) !== 'admin') {
            abort(404, 'Admin account not found.');
        }

        return $user;
    }
}
