<?php

namespace Database\Seeders;

use App\Models\LearningMode;
use Illuminate\Database\Seeder;

class LearningModeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'public_id' => 'learning-mode-online',
                'name' => 'PURE ONLINE CLASS',
                'description' => null,
                'status' => 'active',
                'sort_order' => 1,
            ],
            [
                'public_id' => 'learning-mode-face-to-face',
                'name' => 'FACE TO FACE CLASS',
                'description' => null,
                'status' => 'active',
                'sort_order' => 2,
            ],
            [
                'public_id' => 'learning-mode-blended',
                'name' => 'BLENDED LEARNING (ACCESS TO ONLINE AND FACE TO FACE CLASS)',
                'description' => null,
                'status' => 'active',
                'sort_order' => 3,
            ],
        ];

        foreach ($rows as $row) {
            LearningMode::query()->updateOrCreate(
                ['public_id' => $row['public_id']],
                $row
            );
        }
    }
}
