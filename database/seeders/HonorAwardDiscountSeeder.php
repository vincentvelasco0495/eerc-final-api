<?php

namespace Database\Seeders;

use App\Models\HonorAwardDiscount;
use Illuminate\Database\Seeder;

class HonorAwardDiscountSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'public_id' => 'honor-award-discount-president-quiz-champion',
                'name' => 'ACES/PICE PRESIDENT/ MAGNA OR SUMMA CUMLAUDE, CHAMPION OF NATIONAL QUIZ SHOW (MATEMATICS RELATED)',
                'description' => null,
                'status' => 'active',
                'sort_order' => 1,
            ],
            [
                'public_id' => 'honor-award-discount-cum-laude-finalist',
                'name' => 'CUM LAUDE/NATIONAL QUIZ SHOW FINALIST/ FREE VOUCHER',
                'description' => null,
                'status' => 'active',
                'sort_order' => 2,
            ],
            [
                'public_id' => 'honor-award-discount-quizzer-officer',
                'name' => 'NATIONAL QUIZZER PACTICIPANT / PICE/ACES OFFICER',
                'description' => null,
                'status' => 'active',
                'sort_order' => 3,
            ],
            [
                'public_id' => 'honor-award-discount-former-reviewee',
                'name' => 'FORMER REVIEWEE',
                'description' => null,
                'status' => 'active',
                'sort_order' => 4,
            ],
            [
                'public_id' => 'honor-award-discount-none',
                'name' => 'NONE',
                'description' => null,
                'status' => 'active',
                'sort_order' => 5,
            ],
        ];

        foreach ($rows as $row) {
            HonorAwardDiscount::query()->updateOrCreate(
                ['public_id' => $row['public_id']],
                $row
            );
        }
    }
}
