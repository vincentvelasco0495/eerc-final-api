<?php

namespace App\Providers;

use App\Models\AboutPageSection;
use App\Models\Assignment;
use App\Models\BankPaymentMethod;
use App\Models\BatchEnroll;
use App\Models\BranchEnroll;
use App\Models\ContactPageSection;
use App\Models\Course;
use App\Models\EwalletPaymentMethod;
use App\Models\HomepageSection;
use App\Models\HonorAwardDiscount;
use App\Models\Instructor;
use App\Models\LearningMode;
use App\Models\LessonMaterial;
use App\Models\Module;
use App\Models\PackageEnroll;
use App\Models\Program;
use App\Models\Quiz;
use App\Models\ReviewSchedule;
use App\Models\RolePagePermission;
use App\Services\EnrollmentSchemaService;
use App\Services\PagePermissionService;
use App\Support\LmsCache;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
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

        $this->registerCacheInvalidation();

        try {
            if (Schema::hasTable('enrollments')) {
                EnrollmentSchemaService::ensureFormDataColumns();
            }
        } catch (\Throwable) {
            // Database may be unavailable during install/CI; migrate or next request will heal schema.
        }
    }

    protected function registerCacheInvalidation(): void
    {
        $listen = static function (array $models, callable $bust): void {
            foreach ($models as $modelClass) {
                if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
                    continue;
                }
                $modelClass::saved($bust);
                $modelClass::deleted($bust);
            }
        };

        $listen([HomepageSection::class, AboutPageSection::class, ContactPageSection::class], static function () {
            LmsCache::bustCms();
        });
        $listen([
            Program::class,
            Course::class,
            Module::class,
            Quiz::class,
            Assignment::class,
            LessonMaterial::class,
        ], static function () {
            LmsCache::bustCatalog();
        });
        $listen([Instructor::class], static function () {
            LmsCache::bustInstructors();
        });
        $listen([BankPaymentMethod::class, EwalletPaymentMethod::class], static function () {
            LmsCache::bustPayments();
        });
        $listen([RolePagePermission::class], static function () {
            app(PagePermissionService::class)->clearCache();
        });
        $listen([
            BatchEnroll::class,
            LearningMode::class,
            BranchEnroll::class,
            HonorAwardDiscount::class,
            PackageEnroll::class,
            ReviewSchedule::class,
        ], static function () {
            LmsCache::bustEnrollmentOptions();
        });
    }
}
