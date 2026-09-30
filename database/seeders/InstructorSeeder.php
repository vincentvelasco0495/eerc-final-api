<?php

namespace Database\Seeders;

use App\Models\Instructor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo instructor roster rows linked to `users` (normalized).
 * Run: `php artisan db:seed --class=InstructorSeeder`
 */
class InstructorSeeder extends Seeder
{
    public function run(): void
    {
        $extraUsers = [
            [
                'public_uid' => 'inst-user-hannah',
                'name' => 'Hannah Cruz',
                'email' => 'hannah.cruz@eerc.edu',
                'role' => 'instructor',
            ],
            [
                'public_uid' => 'inst-user-miguel',
                'name' => 'Miguel Santos',
                'email' => 'miguel.santos@eerc.edu',
                'role' => 'instructor',
            ],
            [
                'public_uid' => 'inst-user-priya',
                'name' => 'Priya Nandakumar',
                'email' => 'priya.n@eerc.edu',
                'role' => 'instructor',
            ],
            [
                'public_uid' => 'inst-user-jordan',
                'name' => 'Jordan Lee',
                'email' => 'jordan.lee@eerc.edu',
                'role' => 'instructor',
            ],
            [
                'public_uid' => 'inst-user-sam',
                'name' => 'Sam Okonkwo',
                'email' => 'sam.okonkwo@eerc.edu',
                'role' => 'instructor',
            ],
        ];

        foreach ($extraUsers as $row) {
            User::query()->updateOrCreate(
                ['public_uid' => $row['public_uid']],
                [
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'role' => $row['role'],
                    'status' => 'active',
                    'password' => Hash::make('password'),
                ]
            );
        }

        $profiles = [
            [
                'public_uid' => 'learner-01',
                'user_status' => 'active',
                'achievements' => null,
                'profile_path' => null,
            ],
            [
                'public_uid' => 'inst-user-hannah',
                'user_status' => 'active',
                'achievements' => '<p>Lead CE coordinator.</p>',
                'profile_path' => null,
            ],
            [
                'public_uid' => 'inst-user-miguel',
                'user_status' => 'active',
                'achievements' => null,
                'profile_path' => null,
            ],
            [
                'public_uid' => 'inst-user-priya',
                'user_status' => 'active',
                'achievements' => null,
                'profile_path' => null,
            ],
            [
                'public_uid' => 'inst-user-jordan',
                'user_status' => 'inactive',
                'achievements' => '<p>On sabbatical.</p>',
                'profile_path' => null,
            ],
            [
                'public_uid' => 'inst-user-sam',
                'user_status' => 'active',
                'achievements' => null,
                'profile_path' => null,
            ],
        ];

        foreach ($profiles as $spec) {
            $user = User::query()->where('public_uid', $spec['public_uid'])->first();
            if (! $user) {
                continue;
            }
            $user->status = $spec['user_status'];
            $user->save();

            Instructor::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'achievements' => $spec['achievements'],
                    'profile_path' => $spec['profile_path'],
                ]
            );
        }
    }
}
