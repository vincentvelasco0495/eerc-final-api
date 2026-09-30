<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Validation\Validator;

class StudentProfileValidation
{
    public static function phoneDigits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    public static function isValidPhone(?string $phone): bool
    {
        if ($phone === null || trim($phone) === '') {
            return false;
        }

        $digits = strlen(self::phoneDigits($phone));

        return $digits >= 10 && $digits <= 15;
    }

    public static function isAtLeast18(?string $birthday): bool
    {
        if ($birthday === null || trim($birthday) === '') {
            return false;
        }

        try {
            $birth = Carbon::parse($birthday)->startOfDay();
        } catch (\Throwable) {
            return false;
        }

        $cutoff = now()->startOfDay()->subYears(18);

        return $birth->lte($cutoff);
    }

    public static function applyToValidator(Validator $validator, bool $requireFields = true): void
    {
        $validator->after(function (Validator $v) use ($requireFields) {
            $data = $v->getData();

            if ($requireFields || array_key_exists('phoneNumber', $data)) {
                $phone = $data['phoneNumber'] ?? null;
                if ($phone === null || trim((string) $phone) === '') {
                    $v->errors()->add('phoneNumber', 'Phone number is required.');
                } elseif (! self::isValidPhone(is_string($phone) ? $phone : (string) $phone)) {
                    $v->errors()->add('phoneNumber', 'Enter a valid phone number (10–15 digits).');
                }
            }

            if ($requireFields || array_key_exists('birthday', $data)) {
                $birthday = $data['birthday'] ?? null;
                if ($birthday === null || trim((string) $birthday) === '') {
                    $v->errors()->add('birthday', 'Birthday is required.');
                } elseif (! self::isAtLeast18(is_string($birthday) ? $birthday : (string) $birthday)) {
                    $v->errors()->add('birthday', 'You must be at least 18 years old.');
                }
            }
        });
    }
}
