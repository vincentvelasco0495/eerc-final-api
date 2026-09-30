<?php

namespace App\Providers;

use App\Services\EnrollmentSchemaService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $key = (string) ($request->user()?->id ?: $request->ip());
            if ($request->is('api/video-uploads*')) {
                return Limit::perMinute(1200)->by($key);
            }

            return Limit::perMinute(60)->by($key);
        });

        try {
            if (Schema::hasTable('enrollments')) {
                EnrollmentSchemaService::ensureFormDataColumns();
            }
        } catch (\Throwable) {
            // Database may be unavailable during install/CI; migrate or next request will heal schema.
        }
    }
}
