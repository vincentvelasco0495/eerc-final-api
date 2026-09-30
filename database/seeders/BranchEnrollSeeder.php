<?php

namespace Database\Seeders;

use App\Models\BranchEnroll;
use Illuminate\Database\Seeder;

class BranchEnrollSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'public_id' => 'branch-enroll-manila',
                'name' => 'MANILA BRANCH',
                'description' => null,
                'status' => 'active',
                'sort_order' => 1,
            ],
            [
                'public_id' => 'branch-enroll-baguio',
                'name' => 'BAGUIO BRANCH',
                'description' => null,
                'status' => 'active',
                'sort_order' => 2,
            ],
            [
                'public_id' => 'branch-enroll-legazpi',
                'name' => 'LEGAZPI BRANCH',
                'description' => null,
                'status' => 'active',
                'sort_order' => 3,
            ],
            [
                'public_id' => 'branch-enroll-cebu',
                'name' => 'CEBU BRANCH',
                'description' => null,
                'status' => 'active',
                'sort_order' => 4,
            ],
            [
                'public_id' => 'branch-enroll-pure-online',
                'name' => 'PURE ONLINE CLASS',
                'description' => null,
                'status' => 'active',
                'sort_order' => 5,
            ],
        ];

        foreach ($rows as $row) {
            BranchEnroll::query()->updateOrCreate(
                ['public_id' => $row['public_id']],
                $row
            );
        }
    }
}
