<?php

namespace App\Support;

class LessonPlaybackToken
{
    public static function issue(string $materialPublicId, int $ttlSeconds = 28800): string
    {
        $exp = time() + max(60, $ttlSeconds);
        $payload = $materialPublicId.'|'.$exp;
        $sig = hash_hmac('sha256', $payload, self::key());

        return $payload.'.'.$sig;
    }

    public static function assertValid(?string $token, string $materialPublicId): void
    {
        $raw = trim((string) $token);
        if ($raw === '' || ! str_contains($raw, '.')) {
            abort(403, 'This video can only be watched in the lesson player.');
        }

        $dot = strrpos($raw, '.');
        $payload = substr($raw, 0, $dot);
        $sig = substr($raw, $dot + 1);
        $expected = hash_hmac('sha256', $payload, self::key());
        if (! is_string($sig) || $sig === '' || ! hash_equals($expected, $sig)) {
            abort(403, 'This video can only be watched in the lesson player.');
        }

        $parts = explode('|', $payload);
        if (count($parts) !== 2) {
            abort(403, 'This video can only be watched in the lesson player.');
        }

        [$id, $expRaw] = $parts;
        if (! hash_equals($materialPublicId, (string) $id) || (int) $expRaw < time()) {
            abort(403, 'This video can only be watched in the lesson player.');
        }
    }

    protected static function key(): string
    {
        return (string) app('encrypter')->getKey();
    }
}
