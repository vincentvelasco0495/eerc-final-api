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

        return $meta;
    }
}
