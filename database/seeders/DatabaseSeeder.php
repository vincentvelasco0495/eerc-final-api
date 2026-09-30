<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LmsDemoSeeder::class,
            BatchEnrollSeeder::class,
            BranchEnrollSeeder::class,
            ReviewScheduleSeeder::class,
            HonorAwardDiscountSeeder::class,
            PackageEnrollSeeder::class,
            LearningModeSeeder::class,
            HomepageV2SectionSeeder::class,
            AboutPageSectionSeeder::class,
            ContactPageSectionSeeder::class,
            RolePagePermissionSeeder::class,
        ]);
    }
}
