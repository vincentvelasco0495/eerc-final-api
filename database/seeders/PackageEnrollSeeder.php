<?php

namespace Database\Seeders;

use App\Models\PackageEnroll;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class PackageEnrollSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'public_id' => 'package-enroll-review-and-refresher',
                'name' => 'PACKAGE REVIEW AND REFRESHER',
                'description' => null,
                'status' => 'active',
                'sort_order' => 1,
            ],
            [
                'public_id' => 'package-enroll-review-only',
                'name' => 'REVIEW ONLY',
                'description' => null,
                'status' => 'active',
                'sort_order' => 2,
            ],
            [
                'public_id' => 'package-enroll-refresher-only',
                'name' => 'REFRESHER ONLY',
                'description' => null,
                'status' => 'active',
                'sort_order' => 3,
            ],
            [
                'public_id' => 'package-enroll-free-package',
                'name' => 'FREE PACKAGE (ACES OR PICE PRESIDENT, CHAMPION, 1ST AND 2ND PLACER, MAGNA OR SUMA CUMLAUDE)',
                'description' => null,
                'status' => 'active',
                'sort_order' => 4,
            ],
            [
                'public_id' => 'package-enroll-free-review-scholarship-voucher',
                'name' => 'FREE REVIEW SCHOLARSHIP (VOUCHER OR CUMLAUDE) (REVIEW ONLY)',
                'description' => null,
                'status' => 'active',
                'sort_order' => 5,
            ],
            [
                'public_id' => 'package-enroll-pice-aces-officers-quizzer',
                'name' => 'PICE/ACES OFFICERS AND NATIONAL QUIZZER PARTICIPANT',
                'description' => null,
                'status' => 'active',
                'sort_order' => 6,
            ],
            [
                'public_id' => 'package-enroll-free-review-paid-refresher',
                'name' => 'FREE REVIEW SCHOLARSHIP (FREE REVIEW AND PAID FOR REFRESHER )',
                'description' => null,
                'status' => 'active',
                'sort_order' => 7,
            ],
            [
                'public_id' => 'package-enroll-former-eerc-reviewee',
                'name' => 'FORMER EERC REVIEWEE',
                'description' => null,
                'status' => 'active',
                'sort_order' => 8,
            ],
        ];

        $hasLabel = Schema::hasColumn('package_enrolls', 'label');

        foreach ($rows as $row) {
            if ($hasLabel) {
                $row['label'] = $row['name'];
            }

            PackageEnroll::query()->updateOrCreate(
                ['public_id' => $row['public_id']],
                $row
            );
        }
    }
}
