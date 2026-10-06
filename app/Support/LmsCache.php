<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class LmsCache
{
    public const HOMEPAGE_PUBLIC = 'lms:cms:homepage:public';
    public const ABOUT_PUBLIC = 'lms:cms:about:public';
    public const CONTACT_PUBLIC = 'lms:cms:contact:public';
    public const PROGRAMS = 'lms:catalog:programs';
    public const META = 'lms:catalog:meta';
    public const PAYMENT_METHODS = 'lms:catalog:payment-methods';
    public const INSTRUCTORS = 'lms:catalog:instructors';

    public const CMS_TTL = 300;
    public const CATALOG_TTL = 120;
    public const OPTIONS_TTL = 90;
    public const STATS_TTL = 90;

    public static function remember(string $key, int $seconds, callable $callback): mixed
    {
        try {
            return self::store()->remember($key, $seconds, $callback);
        } catch (\Throwable) {
            try {
                return Cache::store('file')->remember($key, $seconds, $callback);
            } catch (\Throwable) {
                return $callback();
            }
        }
    }

    public static function forget(string ...$keys): void
    {
        foreach ($keys as $key) {
            foreach (['default', 'file'] as $store) {
                try {
                    if ($store === 'default') {
                        self::store()->forget($key);
                    } else {
                        Cache::store('file')->forget($key);
                    }
                } catch (\Throwable) {
                    // Cache store may be unavailable during install.
                }
            }
        }
    }

    public static function bustCms(): void
    {
        self::forget(self::HOMEPAGE_PUBLIC, self::ABOUT_PUBLIC, self::CONTACT_PUBLIC);
    }

    public static function bustCatalog(): void
    {
        self::forget(self::PROGRAMS, self::META, self::INSTRUCTORS);
        self::bustEnrollmentOptions();
        self::bustStats();
    }

    public static function bustPayments(): void
    {
        self::forget(self::PAYMENT_METHODS);
    }

    public static function bustInstructors(): void
    {
        self::forget(self::INSTRUCTORS);
    }

    public static function enrollOptionsKey(string $programPublicId = ''): string
    {
        $id = strtolower(trim($programPublicId));
        if ($id === '') {
            $id = 'all';
        }

        return 'lms:enroll-options:'.self::version('lms:enroll-options:v').':'.$id;
    }

    public static function statsKey(string $kind, string $publicId): string
    {
        return 'lms:stats:'.self::version('lms:stats:v').':'.$kind.':'.strtolower(trim($publicId));
    }

    public static function bustEnrollmentOptions(): void
    {
        self::bump('lms:enroll-options:v');
    }

    public static function bustStats(): void
    {
        self::bump('lms:stats:v');
    }

    protected static function store(): Repository
    {
        return Cache::store();
    }

    protected static function version(string $key): int
    {
        try {
            return max(1, (int) self::store()->get($key, 1));
        } catch (\Throwable) {
            try {
                return max(1, (int) Cache::store('file')->get($key, 1));
            } catch (\Throwable) {
                return 1;
            }
        }
    }

    protected static function bump(string $key): void
    {
        try {
            $next = max(1, (int) self::store()->get($key, 0)) + 1;
            self::store()->forever($key, $next);
        } catch (\Throwable) {
            try {
                $file = Cache::store('file');
                $next = max(1, (int) $file->get($key, 0)) + 1;
                $file->forever($key, $next);
            } catch (\Throwable) {
                //
            }
        }
    }
}
