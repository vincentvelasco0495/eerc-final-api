<?php

return [

    /**
     * Seeder / docs only. LMS HTTP routes use the Sanctum-authenticated user (`auth:sanctum`).
     */
    'actor_public_uid' => env('LMS_ACTOR_PUBLIC_UID', 'learner-01'),

    /*
    | Video lessons: chunked authoring uploads + concurrent HTML5 playback.
    | Set LMS_VIDEO_ACCEL_REDIRECT (nginx internal location prefix) so PHP
    | does not hold a worker per watcher — needed around ~thousands of streams.
    */
    'video' => [
        'max_bytes' => (int) env('LMS_VIDEO_MAX_BYTES', 8 * 1024 * 1024 * 1024),
        'chunk_bytes' => (int) env('LMS_VIDEO_CHUNK_BYTES', 1048576),
        'accel_redirect' => env('LMS_VIDEO_ACCEL_REDIRECT'),
        'complete_at_percent' => (int) env('LMS_VIDEO_COMPLETE_AT_PERCENT', 90),
        'presence_seconds' => (int) env('LMS_VIDEO_PRESENCE_SECONDS', 90),
        'playback_ttl' => (int) env('LMS_VIDEO_PLAYBACK_TTL', 28800),
    ],

];
