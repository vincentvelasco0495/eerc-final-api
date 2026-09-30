<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo student roster rows linked to `users`.
 */
class StudentSeeder extends Seeder
{
    public function run(): void
    {
        $extraUsers = [
            [
                'public_uid' => 'stud-user-aria',
                'name' => 'Aria Mendoza',
                'email' => 'aria.mendoza@eerc.edu',
                'role' => 'student',
                'status' => 'active',
            ],
            [
                'public_uid' => 'stud-user-chen',
                'name' => 'Wei Chen',
                'email' => 'wei.chen@eerc.edu',
                'role' => 'student',
                'status' => 'active',
            ],
            [
                'public_uid' => 'stud-user-sofia',
                'name' => 'Sofia Reyes',
                'email' => 'sofia.reyes@eerc.edu',
                'role' => 'student',
                'status' => 'inactive',
            ],
        ];

        foreach ($extraUsers as $row) {
            User::query()->updateOrCreate(
                ['public_uid' => $row['public_uid']],
                [
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'role' => $row['role'],
                    'status' => $row['status'],
                    'password' => Hash::make('password'),
                ]
            );
        }

        $rows = [
            [
                'public_uid' => 'eerc-demo-student-user',
                'user_status' => 'active',
                'notes' => '<p>Prefers evening cohort sessions.</p>',
                'profile_path' => null,
            ],
            [
                'public_uid' => 'learner-01',
                'user_status' => 'active',
                'notes' => null,
                'profile_path' => null,
            ],
            [
                'public_uid' => 'stud-user-aria',
                'user_status' => 'active',
                'notes' => null,
                'profile_path' => null,
            ],
            [
                'public_uid' => 'stud-user-chen',
                'user_status' => 'active',
                'notes' => '<p>Scholarship track.</p>',
                'profile_path' => null,
            ],
            [
                'public_uid' => 'stud-user-sofia',
                'user_status' => 'inactive',
                'notes' => null,
                'profile_path' => null,
            ],
        ];

        foreach ($rows as $spec) {
            $user = User::query()->where('public_uid', $spec['public_uid'])->first();
            if (! $user) {
                continue;
            }
            $user->status = $spec['user_status'];
            $user->save();

            Student::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'notes' => $spec['notes'],
                    'profile_path' => $spec['profile_path'],
                ]
            );
        }
    }
}
