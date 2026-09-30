<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    protected $fillable = [
        'public_id',
        'course_id',
        'module_id',
        'title',
        'description',
        'lesson_content_html',
        'settings_json',
        'duration_minutes',
        'attempts_allowed',
        'sort_order',
        'question_count',
        'question_pool_count',
    ];

    protected function casts(): array
    {
        return [
            'settings_json' => 'array',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'quiz_id')->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class, 'quiz_id');
    }
}
