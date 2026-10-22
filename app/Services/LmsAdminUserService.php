<?php

namespace App\Services;

use App\Models\LmsUserProfile;
use App\Models\User;
use Illuminate\Support\Str;

class LmsAdminUserService
{
    public function adminExists(): bool
    {
        return User::query()->whereRaw('LOWER(role) = ?', ['admin'])->exists();
    }

    public function create(string $name, string $email, string $password, string $status = 'active'): User
    {
        $user = User::query()->create([
            'public_uid' => 'user-'.Str::lower(Str::ulid()),
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => 'admin',
            'status' => $status !== '' ? $status : 'active',
        ]);

        LmsUserProfile::query()->create([
            'user_id' => $user->id,
            'watermark_name' => $user->name,
            'joined_at' => now()->toDateString(),
        ]);

        return $user->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function format(User $user): array
    {
        return [
            'id' => $user->public_uid,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status ?? 'active',
            'role' => $user->role,
            'createdAt' => optional($user->created_at)?->toDateString(),
        ];
    }
}
