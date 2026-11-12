<?php

namespace App\Support;

use Illuminate\Support\Str;

class LessonMetaSupport
{
    /** @param  array<string, mixed>|null  $meta */
    public static function sanitize(?array $meta): ?array
    {
        if ($meta === null) {
            return null;
        }

        unset(
            $meta['videoPosterLessonMaterialPublicId'],
            $meta['video_poster_lesson_material_public_id'],
            $meta['startDate'],
            $meta['start_date'],
            $meta['startTime'],
            $meta['start_time'],
            $meta['videoWidthPx'],
            $meta['video_width_px'],
            $meta['unlockAfterPurchase'],
            $meta['unlock_after_purchase'],
        );

        $rawWatermark = $meta['watermarkText'] ?? $meta['watermark_text'] ?? null;
        unset($meta['watermark_text']);
        if ($rawWatermark === null) {
            unset($meta['watermarkText']);
        } else {
            $collapsed = preg_replace('/\s+/u', ' ', strip_tags((string) $rawWatermark));
            $text = trim(is_string($collapsed) ? $collapsed : '');
            if ($text === '') {
                unset($meta['watermarkText']);
            } else {
                $meta['watermarkText'] = Str::limit($text, 120, '');
            }
        }

        $rawColor = $meta['watermarkColor'] ?? $meta['watermark_color'] ?? null;
        unset($meta['watermark_color']);
        $color = self::normalizeWatermarkColor($rawColor);
        if ($color === null) {
            unset($meta['watermarkColor']);
        } else {
            $meta['watermarkColor'] = $color;
        }

        $rawOpacity = $meta['watermarkOpacity'] ?? $meta['watermark_opacity'] ?? null;
        unset($meta['watermark_opacity']);
        $opacity = self::normalizeWatermarkOpacity($rawOpacity);
        if ($opacity === null) {
            unset($meta['watermarkOpacity']);
        } else {
            $meta['watermarkOpacity'] = $opacity;
        }

        if (
            array_key_exists('videoSourceType', $meta)
            || array_key_exists('videoExternalUrl', $meta)
            || array_key_exists('video_external_url', $meta)
        ) {
            $requested = trim((string) ($meta['videoSourceType'] ?? ''));
            $source = $requested === 'teams-link' ? 'teams-link' : 'html-mp4';
            $meta['videoSourceType'] = $source;

            $rawUrl = $meta['videoExternalUrl'] ?? $meta['video_external_url'] ?? null;
            unset($meta['video_external_url']);
            $url = $source === 'teams-link' ? self::normalizeExternalVideoUrl($rawUrl) : null;
            if ($url === null) {
                unset($meta['videoExternalUrl']);
            } else {
                $meta['videoExternalUrl'] = $url;
            }
        }

        return $meta;
    }

    public static function normalizeExternalVideoUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '' || strlen($raw) > 4000 || ! str_starts_with(strtolower($raw), 'https://')) {
            return null;
        }

        $parts = parse_url($raw);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = $host === 'sharepoint.com'
            || str_ends_with($host, '.sharepoint.com')
            || $host === 'sharepoint.us'
            || str_ends_with($host, '.sharepoint.us')
            || $host === 'teams.microsoft.com'
            || str_ends_with($host, '.teams.microsoft.com');

        return $allowed ? $raw : null;
    }

    public static function normalizeWatermarkColor(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if (! preg_match('/^#?([0-9A-Fa-f]{6}|[0-9A-Fa-f]{3})$/', $raw, $match)) {
            return null;
        }

        $hex = $match[1];
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.strtoupper($hex);
    }

    public static function normalizeWatermarkOpacity(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;
        $isFraction = is_float($value) || (is_string($value) && str_contains($value, '.'));
        if ($isFraction && $amount > 0 && $amount <= 1) {
            $amount *= 100;
        }

        return max(0, min(100, (int) round($amount)));
    }
}
