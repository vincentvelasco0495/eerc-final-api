<?php

namespace App\Console\Commands;

use App\Services\R2VideoStorage;
use Illuminate\Console\Command;

class ConfigureR2Cors extends Command
{
    protected $signature = 'r2:configure-cors';

    protected $description = 'Allow the site to upload lesson videos directly to Cloudflare R2';

    public function handle(R2VideoStorage $r2): int
    {
        if (! $r2->configured()) {
            $this->error('Set R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, and R2_BUCKET first.');

            return self::FAILURE;
        }

        $origins = array_merge(
            config('cors.allowed_origins', []),
            [config('lms.frontend_url'), 'https://www.eerclearningcenter.com']
        );
        $r2->configureCors($origins);
        $this->info('R2 bucket CORS now allows uploads from the site.');

        return self::SUCCESS;
    }
}
