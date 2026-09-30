<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLessonProgress extends Model
{
    protected $table = 'user_lesson_progress';

    protected $fillable = [
        'user_id',
        'course_id',
        'lesson_key',
        'progress_percent',
        'last_position_seconds',
        'completed_at',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'last_position_seconds' => 'integer',
            'completed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
