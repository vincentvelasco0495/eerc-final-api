<?php

namespace Database\Seeders;

use App\Models\AdminUpload;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LeaderboardEntry;
use App\Models\LmsUserProfile;
use App\Models\Module;
use App\Models\ModuleResource;
use App\Models\Program;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Subject;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\UserModuleProgress;
use App\Support\LmsMeta;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Normalized rows mirroring `src/services/lms.service.js` demo data (public_id strings stable for API/UI).
 */
class LmsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $marketingTemplates = require dirname(__DIR__).'/data/lms_demo_course_marketing.php';

        foreach ([
            ['public_id' => 'program-ce', 'code' => 'CE', 'title' => 'Civil Engineering', 'description' => 'A guided civil engineering board review path with coaching checkpoints and timed practice.'],
            ['public_id' => 'program-plumbing', 'code' => 'MPL', 'title' => 'Master Plumbing', 'description' => 'Licensure preparation focused on design, code review, and field scenarios.'],
            ['public_id' => 'program-materials', 'code' => 'MSE', 'title' => 'Materials Engineering', 'description' => 'Core material behavior, manufacturing processes, and exam-ready drills.'],
        ] as $row) {
            Program::query()->create($row);
        }

        $courseSpecs = [
            [
                'public_id' => 'course-ce-review',
                'program_public_id' => 'program-ce',
                'slug' => 'ce-board-review',
                'title' => 'CE Board Review',
                'mentor_display_name' => 'Engr. Hannah Cruz',
                'description' => 'Structured review sessions covering hydraulics, structures, and environmental systems.',
                'level' => 'Advanced',
                'total_modules' => 8,
                'hours' => 36,
                'learners_count' => 1842,
                'next_module_public_id' => 'module-hydraulics-review',
                'tags' => ['Streaming only', 'Board exam'],
                'subjects' => ['Hydraulics', 'Structures', 'Environmental Engineering'],
            ],
            [
                'public_id' => 'course-plumbing-mastery',
                'program_public_id' => 'program-plumbing',
                'slug' => 'master-plumbing-fast-track',
                'title' => 'Master Plumbing Fast Track',
                'mentor_display_name' => 'Engr. Miguel Santos',
                'description' => 'Code interpretation, system sizing, and troubleshooting walkthroughs.',
                'level' => 'Intermediate',
                'total_modules' => 7,
                'hours' => 29,
                'learners_count' => 1297,
                'next_module_public_id' => 'module-pipe-sizing-practice',
                'tags' => ['Practice heavy', 'Case studies'],
                'subjects' => ['Plumbing Code', 'Pipe Design', 'Sanitary Systems'],
            ],
            [
                'public_id' => 'course-materials-intensive',
                'program_public_id' => 'program-materials',
                'slug' => 'materials-engineering-intensive',
                'title' => 'Materials Engineering Intensive',
                'mentor_display_name' => 'Dr. Reese Navarro',
                'description' => 'Materials characterization, failures, and thermodynamics for exam prep.',
                'level' => 'Advanced',
                'total_modules' => 9,
                'hours' => 41,
                'learners_count' => 913,
                'next_module_public_id' => 'module-heat-treatment-refresher',
                'tags' => ['Lab aligned', 'Coaching'],
                'subjects' => ['Metallurgy', 'Thermodynamics', 'Failure Analysis'],
            ],
        ];

        foreach ($courseSpecs as $spec) {
            $tags = $spec['tags'] ?? [];
            $subjects = $spec['subjects'] ?? [];

            $course = Course::query()->create([
                'public_id' => $spec['public_id'],
                'program_id' => Program::query()->where('public_id', $spec['program_public_id'])->value('id'),
                'slug' => $spec['slug'],
                'title' => $spec['title'],
                'mentor_display_name' => $spec['mentor_display_name'],
                'description' => $spec['description'],
                'marketing_json' => $marketingTemplates[$spec['public_id']] ?? null,
                'level' => $spec['level'],
                'total_modules' => $spec['total_modules'],
                'hours' => $spec['hours'],
                'learners_count' => $spec['learners_count'],
                'next_module_id' => null,
                'video_hours_label' => $spec['video_hours_label'] ?? null,
                'preview_completed' => (bool) ($spec['preview_completed'] ?? false),
            ]);

            foreach ($tags as $name) {
                $tag = Tag::query()->firstOrCreate(['name' => $name]);
                $course->tags()->syncWithoutDetaching([$tag->id]);
            }
            foreach ($subjects as $name) {
                $subject = Subject::query()->firstOrCreate(['name' => $name]);
                $course->subjects()->syncWithoutDetaching([$subject->id]);
            }
        }

        $moduleSeed = [
            ['public_id' => 'module-hydraulics-review', 'course_public_id' => 'course-ce-review', 'title' => 'Hydraulics Review Session', 'subject' => 'Hydraulics', 'topic' => 'Fluid Flow', 'subtopic' => 'Bernoulli and Energy Losses', 'learning_flow_step' => 'Review', 'duration_label' => '42 min', 'last_position' => '31:10', 'progress' => 74, 'is_visible' => true, 'streaming_only' => true, 'resources' => ['Video', 'PDF', 'eBook'], 'summary' => 'Review losses, pressure heads, and exam shortcuts.', 'sort_order' => 1],
            ['public_id' => 'module-hydraulics-practice', 'course_public_id' => 'course-ce-review', 'title' => 'Hydraulics Practice Problems', 'subject' => 'Hydraulics', 'topic' => 'Fluid Flow', 'subtopic' => 'Pump and Pipeline Problems', 'learning_flow_step' => 'Practice', 'duration_label' => '28 min', 'last_position' => '00:00', 'progress' => 0, 'is_visible' => true, 'streaming_only' => true, 'resources' => ['Video', 'PDF'], 'summary' => 'Timed practice set with guided solutions.', 'sort_order' => 2],
            ['public_id' => 'module-structural-refresher', 'course_public_id' => 'course-ce-review', 'title' => 'Structural Refresher', 'subject' => 'Structures', 'topic' => 'Reinforced Concrete', 'subtopic' => 'Shear and Flexure', 'learning_flow_step' => 'Refresher', 'duration_label' => '25 min', 'last_position' => '12:44', 'progress' => 52, 'is_visible' => false, 'streaming_only' => true, 'resources' => ['PDF', 'eBook'], 'summary' => 'Formula refresher for high-frequency board questions.', 'sort_order' => 3],
            ['public_id' => 'module-pipe-sizing-practice', 'course_public_id' => 'course-plumbing-mastery', 'title' => 'Pipe Sizing Practice', 'subject' => 'Pipe Design', 'topic' => 'Sizing', 'subtopic' => 'Fixture Units', 'learning_flow_step' => 'Practice', 'duration_label' => '35 min', 'last_position' => '09:30', 'progress' => 33, 'is_visible' => true, 'streaming_only' => true, 'resources' => ['Video', 'PDF'], 'summary' => 'Practice scenarios for domestic and sanitary systems.', 'sort_order' => 1],
            ['public_id' => 'module-code-final-coaching', 'course_public_id' => 'course-plumbing-mastery', 'title' => 'Code Review Final Coaching', 'subject' => 'Plumbing Code', 'topic' => 'Compliance', 'subtopic' => 'Inspection and Exceptions', 'learning_flow_step' => 'Final Coaching', 'duration_label' => '48 min', 'last_position' => '00:00', 'progress' => 0, 'is_visible' => true, 'streaming_only' => true, 'resources' => ['Video', 'eBook'], 'summary' => 'Instructor-led final coaching before mock exam day.', 'sort_order' => 2],
            ['public_id' => 'module-heat-treatment-refresher', 'course_public_id' => 'course-materials-intensive', 'title' => 'Heat Treatment Refresher', 'subject' => 'Metallurgy', 'topic' => 'Heat Treatment', 'subtopic' => 'Phase Transformation', 'learning_flow_step' => 'Refresher', 'duration_label' => '32 min', 'last_position' => '16:25', 'progress' => 58, 'is_visible' => true, 'streaming_only' => true, 'resources' => ['Video', 'PDF', 'eBook'], 'summary' => 'Fast review of heat treatment charts and material response.', 'sort_order' => 1],
        ];

        foreach ($moduleSeed as $m) {
            $resources = $m['resources'] ?? [];
            $coursePublicId = $m['course_public_id'];
            unset($m['resources'], $m['last_position'], $m['progress'], $m['course_public_id']);
            $m['course_id'] = Course::query()->where('public_id', $coursePublicId)->value('id');
            $module = Module::query()->create($m);
            foreach ($resources as $j => $format) {
                ModuleResource::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'module_id' => $module->id,
                    'format' => $format,
                    'sort_order' => $j,
                ]);
            }
        }

        foreach ($courseSpecs as $spec) {
            $nid = Module::query()->where('public_id', $spec['next_module_public_id'])->value('id');
            Course::query()->where('public_id', $spec['public_id'])->update(['next_module_id' => $nid]);
        }

        $quizSpecs = [
            ['public_id' => 'quiz-hydraulics-timed', 'course_public_id' => 'course-ce-review', 'module_public_id' => 'module-hydraulics-review', 'title' => 'Hydraulics Timed Quiz', 'duration_minutes' => 20, 'attempts_allowed' => 3, 'question_count' => 20, 'question_pool_count' => 80],
            ['public_id' => 'quiz-plumbing-code', 'course_public_id' => 'course-plumbing-mastery', 'module_public_id' => 'module-code-final-coaching', 'title' => 'Plumbing Code Quiz', 'duration_minutes' => 15, 'attempts_allowed' => 4, 'question_count' => 15, 'question_pool_count' => 48],
        ];
        foreach ($quizSpecs as $q) {
            Quiz::query()->create([
                'public_id' => $q['public_id'],
                'course_id' => Course::query()->where('public_id', $q['course_public_id'])->value('id'),
                'module_id' => Module::query()->where('public_id', $q['module_public_id'])->value('id'),
                'title' => $q['title'],
                'duration_minutes' => $q['duration_minutes'],
                'attempts_allowed' => $q['attempts_allowed'],
                'question_count' => $q['question_count'],
                'question_pool_count' => $q['question_pool_count'],
            ]);
        }

        $u1 = User::query()->create([
            'public_uid' => 'learner-01',
            'name' => 'Alex E. Rivera',
            'email' => 'alex.rivera@eerc.edu',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $u2 = User::query()->create([
            'public_uid' => 'eerc-demo-student-user',
            'name' => 'Mina Santos',
            'email' => 'mina@demo.edu',
            'password' => Hash::make('password'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $u3 = User::query()->create([
            'public_uid' => 'eerc-demo-instructor-user',
            'name' => 'Dr. Reese Navarro',
            'email' => 'reese@demo.edu',
            'password' => Hash::make('password'),
            'role' => 'instructor',
            'status' => 'active',
        ]);

        LmsUserProfile::query()->create([
            'user_id' => $u1->id,
            'program_id' => Program::query()->where('public_id', 'program-ce')->value('id'),
            'streak_days' => 14,
            'watermark_name' => 'Alex E. Rivera',
            'session_warning' => true,
            'joined_at' => '2026-01-12',
        ]);

        LmsUserProfile::query()->create([
            'user_id' => $u2->id,
            'program_id' => Program::query()->where('public_id', 'program-plumbing')->value('id'),
            'streak_days' => 0,
            'watermark_name' => $u2->name,
            'session_warning' => false,
            'joined_at' => '2026-02-01',
        ]);

        LmsUserProfile::query()->create([
            'user_id' => $u3->id,
            'program_id' => Program::query()->where('public_id', 'program-materials')->value('id'),
            'streak_days' => 0,
            'watermark_name' => $u3->name,
            'session_warning' => false,
            'joined_at' => '2025-11-15',
        ]);

        foreach (['top10', 'consistency'] as $key) {
            UserBadge::query()->create([
                'user_id' => $u1->id,
                'badge_key' => $key,
                'badge_label' => LmsMeta::BADGE_LABELS[$key] ?? $key,
            ]);
        }

        foreach ($moduleSeed as $m) {
            UserModuleProgress::query()->create([
                'user_id' => $u1->id,
                'module_id' => Module::query()->where('public_id', $m['public_id'])->value('id'),
                'progress_percent' => (int) $m['progress'],
                'last_position_label' => $m['last_position'],
            ]);
        }

        $hydQuizId = Quiz::query()->where('public_id', 'quiz-hydraulics-timed')->value('id');
        $plumbQuizId = Quiz::query()->where('public_id', 'quiz-plumbing-code')->value('id');

        QuizAttempt::query()->create([
            'public_id' => 'attempt-001',
            'user_id' => $u1->id,
            'quiz_id' => $hydQuizId,
            'attempted_on' => '2026-04-20',
            'score' => 84,
            'duration_used_label' => '16m 10s',
            'correct_answers' => 17,
            'total_questions' => 20,
        ]);
        QuizAttempt::query()->create([
            'public_id' => 'attempt-002',
            'user_id' => $u1->id,
            'quiz_id' => $hydQuizId,
            'attempted_on' => '2026-04-22',
            'score' => 88,
            'duration_used_label' => '14m 55s',
            'correct_answers' => 18,
            'total_questions' => 20,
        ]);
        QuizAttempt::query()->create([
            'public_id' => 'attempt-003',
            'user_id' => $u1->id,
            'quiz_id' => $plumbQuizId,
            'attempted_on' => '2026-04-19',
            'score' => 76,
            'duration_used_label' => '11m 37s',
            'correct_answers' => 11,
            'total_questions' => 15,
        ]);

        Enrollment::query()->create([
            'public_id' => 'enrollment-001',
            'user_id' => $u1->id,
            'program_id' => Program::query()->where('public_id', 'program-ce')->value('id'),
            'course_id' => Course::query()->where('public_id', 'course-ce-review')->value('id'),
            'status' => 'approved',
            'submitted_at' => '2026-04-10',
        ]);
        Enrollment::query()->create([
            'public_id' => 'enrollment-002',
            'user_id' => $u1->id,
            'program_id' => Program::query()->where('public_id', 'program-materials')->value('id'),
            'course_id' => Course::query()->where('public_id', 'course-materials-intensive')->value('id'),
            'status' => 'pending',
            'submitted_at' => '2026-04-21',
        ]);

        $leaderboardRows = [
            ['period' => 'daily', 'rank_order' => 1, 'user_id' => $u1->id, 'display_name' => 'Alex E. Rivera', 'program_code' => 'CE', 'score' => 985, 'badge_key' => 'top10'],
            ['period' => 'daily', 'rank_order' => 2, 'display_name' => 'Mina Santos', 'program_code' => 'MPL', 'score' => 960, 'badge_key' => 'mostImproved'],
            ['period' => 'daily', 'rank_order' => 3, 'display_name' => 'Caleb Lim', 'program_code' => 'MSE', 'score' => 931, 'badge_key' => 'consistency'],
            ['period' => 'weekly', 'rank_order' => 1, 'display_name' => 'Mina Santos', 'program_code' => 'MPL', 'score' => 6720, 'badge_key' => 'top10'],
            ['period' => 'weekly', 'rank_order' => 2, 'user_id' => $u1->id, 'display_name' => 'Alex E. Rivera', 'program_code' => 'CE', 'score' => 6550, 'badge_key' => 'consistency'],
            ['period' => 'weekly', 'rank_order' => 3, 'display_name' => 'Jiro Tan', 'program_code' => 'MSE', 'score' => 6430, 'badge_key' => 'mostImproved'],
            ['period' => 'overall', 'rank_order' => 1, 'user_id' => $u1->id, 'display_name' => 'Alex E. Rivera', 'program_code' => 'CE', 'score' => 31220, 'badge_key' => 'top10'],
            ['period' => 'overall', 'rank_order' => 2, 'display_name' => 'Diane Uy', 'program_code' => 'MPL', 'score' => 30110, 'badge_key' => 'top10'],
            ['period' => 'overall', 'rank_order' => 3, 'display_name' => 'Mina Santos', 'program_code' => 'MPL', 'score' => 29440, 'badge_key' => 'mostImproved'],
        ];
        foreach ($leaderboardRows as $row) {
            LeaderboardEntry::query()->create($row);
        }

        AdminUpload::query()->create([
            'public_id' => 'upload-1',
            'user_id' => $u1->id,
            'title' => 'Hydraulics Solution Manual',
            'asset_type' => 'PDF',
            'status' => 'Published',
        ]);

        $this->call(InstructorSeeder::class);
        $this->call(StudentSeeder::class);
    }
}
