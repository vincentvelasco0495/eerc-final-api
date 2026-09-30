<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttempt extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'quiz_id',
        'attempted_on',
        'score',
        'duration_used_label',
        'duration_used_seconds',
        'correct_answers',
        'total_questions',
    ];

    protected function casts(): array
    {
        return [
            'attempted_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }
}
