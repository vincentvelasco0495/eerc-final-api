<?php

namespace App\Services;

use App\Models\LmsUserProfile;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
     * Remove an admin from the admin list and free the email so it can be registered again.
     * A linked student roster row is soft-deleted so the retired address does not stay on that list.
     */
    public function archiveRetiredAdminLogin(User $user): bool
    {
        if (strtolower((string) $user->role) !== 'admin') {
            return false;
        }

        DB::transaction(function () use ($user) {
            $student = Student::query()->where('user_id', $user->id)->first();
            if ($student) {
                $student->delete();
            }

            $user->tokens()->delete();
            $user->email = sprintf('deleted.%d.%s@users.invalid', $user->id, Str::lower((string) Str::ulid()));
            $user->status = 'inactive';
            $user->role = 'retired';
            $user->save();
        });

        return true;
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
