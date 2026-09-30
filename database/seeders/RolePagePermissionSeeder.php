<?php

namespace Database\Seeders;

use App\Models\RolePagePermission;
use App\Services\PagePermissionService;
use Illuminate\Database\Seeder;

class RolePagePermissionSeeder extends Seeder
{
    public function run(): void
    {
        RolePagePermission::query()->delete();

        $rows = [
            // Admin
            ['role' => 'admin', 'path' => '/dashboard', 'match_type' => 'exact', 'query' => null, 'label' => 'Admin dashboard', 'sort_order' => 10],
            ['role' => 'admin', 'path' => '/course-curriculum', 'match_type' => 'exact', 'query' => ['new' => '1'], 'label' => 'Create course (curriculum)', 'sort_order' => 20],
            ['role' => 'admin', 'path' => '/course-curriculum/*/edit', 'match_type' => 'path_pattern', 'query' => null, 'label' => 'Edit course curriculum', 'sort_order' => 21],
            ['role' => 'admin', 'path' => '/course-curriculum', 'match_type' => 'exact', 'query' => null, 'label' => 'Course curriculum', 'sort_order' => 22],
            ['role' => 'admin', 'path' => '/setting-program', 'match_type' => 'prefix', 'query' => null, 'label' => 'Programs settings', 'sort_order' => 30],
            ['role' => 'admin', 'path' => '/setting-batch-enroll', 'match_type' => 'prefix', 'query' => null, 'label' => 'Batch enroll settings', 'sort_order' => 31],
            ['role' => 'admin', 'path' => '/setting-mode-of-learning', 'match_type' => 'prefix', 'query' => null, 'label' => 'Mode of learning settings', 'sort_order' => 32],
            ['role' => 'admin', 'path' => '/setting-branch-enroll', 'match_type' => 'prefix', 'query' => null, 'label' => 'Branch to enroll settings', 'sort_order' => 33],
            ['role' => 'admin', 'path' => '/setting-review-schedule', 'match_type' => 'prefix', 'query' => null, 'label' => 'Review schedule settings', 'sort_order' => 34],
            ['role' => 'admin', 'path' => '/setting-honors-awards-discount', 'match_type' => 'prefix', 'query' => null, 'label' => 'Honors awards discount settings', 'sort_order' => 35],
            ['role' => 'admin', 'path' => '/setting-package-enroll', 'match_type' => 'prefix', 'query' => null, 'label' => 'Package enroll settings', 'sort_order' => 36],
            ['role' => 'admin', 'path' => '/setting-instructor', 'match_type' => 'prefix', 'query' => null, 'label' => 'Instructors settings', 'sort_order' => 37],
            ['role' => 'admin', 'path' => '/setting-student', 'match_type' => 'prefix', 'query' => null, 'label' => 'Students settings', 'sort_order' => 38],
            ['role' => 'admin', 'path' => '/setting-payment', 'match_type' => 'prefix', 'query' => null, 'label' => 'Payment settings', 'sort_order' => 39],
            ['role' => 'admin', 'path' => '/setting-profile', 'match_type' => 'exact', 'query' => null, 'label' => 'Profile settings', 'sort_order' => 40],
            ['role' => 'admin', 'path' => '/enrollment', 'match_type' => 'prefix', 'query' => null, 'label' => 'Enrollment', 'sort_order' => 41],
            ['role' => 'admin', 'path' => '/payment-history', 'match_type' => 'prefix', 'query' => null, 'label' => 'Payment history', 'sort_order' => 42],
            ['role' => 'admin', 'path' => '/announcement', 'match_type' => 'exact', 'query' => null, 'label' => 'Announcements', 'sort_order' => 43],
            ['role' => 'admin', 'path' => '/feedback', 'match_type' => 'prefix', 'query' => null, 'label' => 'Contact feedback', 'sort_order' => 44],
            ['role' => 'admin', 'path' => '/gradebook', 'match_type' => 'exact', 'query' => null, 'label' => 'Gradebook', 'sort_order' => 45],
            ['role' => 'admin', 'path' => '/student-quizzes', 'match_type' => 'prefix', 'query' => null, 'label' => 'Student quizzes', 'sort_order' => 46],
            ['role' => 'admin', 'path' => '/assignment', 'match_type' => 'prefix', 'query' => null, 'label' => 'Assignments', 'sort_order' => 47],
            ['role' => 'admin', 'path' => '/content-management', 'match_type' => 'prefix', 'query' => null, 'label' => 'Content management', 'sort_order' => 48],
            ['role' => 'admin', 'path' => '/admin', 'match_type' => 'prefix', 'query' => null, 'label' => 'Admin tools', 'sort_order' => 50],
            ['role' => 'admin', 'path' => '/quizzes/history', 'match_type' => 'prefix', 'query' => null, 'label' => 'Quiz attempt history', 'sort_order' => 51],
            ['role' => 'admin', 'path' => '/quizzes', 'match_type' => 'prefix', 'query' => null, 'label' => 'Quizzes', 'sort_order' => 52],
            ['role' => 'admin', 'path' => '/course-details', 'match_type' => 'prefix', 'query' => null, 'label' => 'Course details', 'sort_order' => 53],
            ['role' => 'admin', 'path' => '/courses', 'match_type' => 'prefix', 'query' => null, 'label' => 'Courses', 'sort_order' => 54],

            // Instructor
            ['role' => 'instructor', 'path' => '/instructor-home', 'match_type' => 'exact', 'query' => null, 'label' => 'Instructor dashboard', 'sort_order' => 10],
            ['role' => 'instructor', 'path' => '/course-curriculum/*/edit', 'match_type' => 'path_pattern', 'query' => null, 'label' => 'Edit course curriculum', 'sort_order' => 20],
            ['role' => 'instructor', 'path' => '/setting-mode-of-learning', 'match_type' => 'prefix', 'query' => null, 'label' => 'Mode of learning settings', 'sort_order' => 31],
            ['role' => 'instructor', 'path' => '/setting-branch-enroll', 'match_type' => 'prefix', 'query' => null, 'label' => 'Branch to enroll settings', 'sort_order' => 32],
            ['role' => 'instructor', 'path' => '/setting-review-schedule', 'match_type' => 'prefix', 'query' => null, 'label' => 'Review schedule settings', 'sort_order' => 33],
            ['role' => 'instructor', 'path' => '/setting-honors-awards-discount', 'match_type' => 'prefix', 'query' => null, 'label' => 'Honors awards discount settings', 'sort_order' => 34],
            ['role' => 'instructor', 'path' => '/setting-package-enroll', 'match_type' => 'prefix', 'query' => null, 'label' => 'Package enroll settings', 'sort_order' => 35],
            ['role' => 'instructor', 'path' => '/setting-profile', 'match_type' => 'exact', 'query' => null, 'label' => 'Profile settings', 'sort_order' => 30],
            ['role' => 'instructor', 'path' => '/enrollment', 'match_type' => 'prefix', 'query' => null, 'label' => 'Enrollment', 'sort_order' => 40],
            ['role' => 'instructor', 'path' => '/payment-history', 'match_type' => 'prefix', 'query' => null, 'label' => 'Payment history', 'sort_order' => 41],
            ['role' => 'instructor', 'path' => '/announcement', 'match_type' => 'exact', 'query' => null, 'label' => 'Announcements', 'sort_order' => 42],
            ['role' => 'instructor', 'path' => '/feedback', 'match_type' => 'prefix', 'query' => null, 'label' => 'Contact feedback', 'sort_order' => 43],
            ['role' => 'instructor', 'path' => '/gradebook', 'match_type' => 'exact', 'query' => null, 'label' => 'Gradebook', 'sort_order' => 44],
            ['role' => 'instructor', 'path' => '/student-quizzes', 'match_type' => 'prefix', 'query' => null, 'label' => 'Student quizzes', 'sort_order' => 45],
            ['role' => 'instructor', 'path' => '/assignment', 'match_type' => 'prefix', 'query' => null, 'label' => 'Assignments', 'sort_order' => 46],
            ['role' => 'instructor', 'path' => '/content-management', 'match_type' => 'prefix', 'query' => null, 'label' => 'Content management', 'sort_order' => 47],
            ['role' => 'instructor', 'path' => '/courses', 'match_type' => 'prefix', 'query' => null, 'label' => 'Courses', 'sort_order' => 48],
            ['role' => 'instructor', 'path' => '/quizzes/history', 'match_type' => 'prefix', 'query' => null, 'label' => 'Quiz attempt history', 'sort_order' => 49],
            ['role' => 'instructor', 'path' => '/quizzes', 'match_type' => 'prefix', 'query' => null, 'label' => 'Quizzes', 'sort_order' => 50],

            // Student
            ['role' => 'student', 'path' => '/available-programs', 'match_type' => 'exact', 'query' => null, 'label' => 'Programs', 'sort_order' => 10],
            ['role' => 'student', 'path' => '/enrollment/apply', 'match_type' => 'prefix', 'query' => null, 'label' => 'Enrollment application', 'sort_order' => 12],
            ['role' => 'student', 'path' => '/assignments', 'match_type' => 'prefix', 'query' => null, 'label' => 'Assignments', 'sort_order' => 13],
            ['role' => 'student', 'path' => '/quizzes', 'match_type' => 'prefix', 'query' => null, 'label' => 'Quizzes', 'sort_order' => 14],
            ['role' => 'student', 'path' => '/quizzes/history', 'match_type' => 'prefix', 'query' => null, 'label' => 'Quiz attempt history', 'sort_order' => 15],
            ['role' => 'student', 'path' => '/settings', 'match_type' => 'exact', 'query' => null, 'label' => 'Settings', 'sort_order' => 16],
            ['role' => 'student', 'path' => '/course-details', 'match_type' => 'prefix', 'query' => null, 'label' => 'Course details', 'sort_order' => 20],
            ['role' => 'student', 'path' => '/courses', 'match_type' => 'prefix', 'query' => null, 'label' => 'Courses', 'sort_order' => 21],
        ];

        foreach ($rows as $row) {
            RolePagePermission::query()->create($row);
        }

        app(PagePermissionService::class)->clearCache();
    }
}
