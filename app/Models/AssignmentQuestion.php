<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentQuestion extends Model
{
    protected $fillable = [
        'assignment_id',
        'prompt',
        'question_type',
        'sort_order',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(AssignmentQuestionOption::class, 'assignment_question_id')->orderBy('sort_order');
    }
}
