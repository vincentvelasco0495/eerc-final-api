<?php

namespace Database\Seeders;

use App\Models\BranchEnroll;
use App\Models\ReviewSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class ReviewScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'public_id' => 'review-schedule-pure-online',
                'branch_public_id' => 'branch-enroll-pure-online',
                'name' => 'PURE ONLINE CLASS MONDAY-THURSDAY 8AM TO 12NN',
                'description' => null,
                'status' => 'active',
                'sort_order' => 1,
                'student_capacity' => 50,
            ],
            [
                'public_id' => 'review-schedule-baguio',
                'branch_public_id' => 'branch-enroll-baguio',
                'name' => 'BAGUIO BRANCH SECTION D 4:30PM TO 8:30PM',
                'description' => null,
                'status' => 'active',
                'sort_order' => 2,
                'student_capacity' => 25,
            ],
            [
                'public_id' => 'review-schedule-manila',
                'branch_public_id' => 'branch-enroll-manila',
                'name' => 'MANILA BRANCH SECTION D TUESDAY TO FRIDAY 4:30PM TO 8:30PM',
                'description' => null,
                'status' => 'active',
                'sort_order' => 3,
                'student_capacity' => 30,
            ],
            [
                'public_id' => 'review-schedule-legazpi',
                'branch_public_id' => 'branch-enroll-legazpi',
                'name' => 'LEGAZPI BRANCH MONDAY TO THURSDAY 8am to 12nn',
                'description' => null,
                'status' => 'active',
                'sort_order' => 4,
                'student_capacity' => 20,
            ],
            [
                'public_id' => 'review-schedule-cebu',
                'branch_public_id' => 'branch-enroll-cebu',
                'name' => 'CEBU BRANCH MONDAY TO THURSDAY 9AM TO 4PM',
                'description' => null,
                'status' => 'active',
                'sort_order' => 5,
                'student_capacity' => 35,
            ],
        ];

        foreach ($rows as $row) {
            $branchId = BranchEnroll::query()
                ->where('public_id', $row['branch_public_id'])
                ->value('id');

            $payload = [
                'name' => $row['name'],
                'description' => $row['description'],
                'status' => $row['status'],
                'sort_order' => $row['sort_order'],
                'student_capacity' => $row['student_capacity'] ?? 30,
                'enrollment_branch_id' => $branchId,
            ];

            if (Schema::hasColumn('review_schedules', 'label')) {
                $payload['label'] = $row['name'];
            }

            $match = ReviewSchedule::query()
                ->where(function ($q) use ($row) {
                    $q->where('public_id', $row['public_id']);

                    if (Schema::hasColumn('review_schedules', 'label')) {
                        $q->orWhere('label', $row['name']);
                    }
                })
                ->first();

            if ($match) {
                $match->update($payload);

                continue;
            }

            ReviewSchedule::query()->create([
                'public_id' => $row['public_id'],
                ...$payload,
            ]);
        }
    }
}
