<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    protected $fillable = ['quiz_id', 'prompt', 'sort_order', 'question_type', 'meta_json'];

    protected $casts = [
        'meta_json' => 'array',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class, 'question_id')->orderBy('sort_order');
    }
}
