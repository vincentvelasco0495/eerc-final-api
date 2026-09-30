<?php

namespace Database\Seeders;

use App\Models\ContactPageSection;
use Illuminate\Database\Seeder;

class ContactPageSectionSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = require database_path('data/contact_page_defaults.php');

        foreach ($defaults as $row) {
            ContactPageSection::query()->updateOrCreate(
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
