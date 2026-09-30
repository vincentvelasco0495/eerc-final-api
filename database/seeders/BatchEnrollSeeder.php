<?php

namespace Database\Seeders;

use App\Models\BatchEnroll;
use App\Models\Program;
use Illuminate\Database\Seeder;

class BatchEnrollSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'suffix' => '1',
                'name' => 'BATCH 1 (TENTATIVE START - APRIL 3RD WEEK)',
                'tentative_start' => 'April 3rd week',
                'description' => '',
                'sort_order' => 1,
            ],
            [
                'suffix' => '2',
                'name' => 'BATCH 2 (TENTATIVE START FIRST WEEK OF JUNE)',
                'tentative_start' => 'First week of June',
                'description' => '<p>For Batch 2 once they start they will join the current topic being discussed in Batch 1 and they will have makeup classes for past topics while having the regular class.</p>',
                'sort_order' => 2,
            ],
        ];

        $programs = Program::query()->orderBy('id')->get();

        if ($programs->isEmpty()) {
            return;
        }

        foreach ($programs as $program) {
            foreach ($templates as $template) {
                BatchEnroll::query()->updateOrCreate(
                    ['public_id' => "batch-enroll-{$template['suffix']}-{$program->public_id}"],
                    [
                        'program_id' => $program->id,
                        'name' => $template['name'],
                        'tentative_start' => $template['tentative_start'],
                        'description' => $template['description'],
                        'status' => 'active',
                        'sort_order' => $template['sort_order'],
                    ]
                );
            }
        }

        // Keep legacy CE-only rows in sync for older databases.
        $ceProgramId = Program::query()->where('public_id', 'program-ce')->value('id');
        if ($ceProgramId) {
            BatchEnroll::query()
                ->where('public_id', 'batch-enroll-1')
                ->update([
                    'program_id' => $ceProgramId,
                    'name' => $templates[0]['name'],
                    'tentative_start' => $templates[0]['tentative_start'],
                ]);

            BatchEnroll::query()
                ->where('public_id', 'batch-enroll-2')
                ->update([
                    'program_id' => $ceProgramId,
                    'name' => $templates[1]['name'],
                    'tentative_start' => $templates[1]['tentative_start'],
                    'description' => $templates[1]['description'],
                ]);
        }
    }
}
