<?php

namespace Database\Seeders;

use App\Models\HomepageSection;
use Illuminate\Database\Seeder;

class HomepageV2SectionSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = require database_path('data/homepage_v2_defaults.php');

        foreach ($defaults as $row) {
            HomepageSection::query()->updateOrCreate(
                ['section_key' => $row['section_key']],
                [
                    'title' => $row['title'],
                    'content_json' => $row['content_json'],
                    'status' => $row['status'],
                    'sort_order' => $row['sort_order'],
                ]
            );
        }
    }
}
