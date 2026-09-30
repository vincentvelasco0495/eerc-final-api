<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentQuestionOption extends Model
{
    protected $fillable = [
        'assignment_question_id',
        'label',
        'is_correct',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(AssignmentQuestion::class, 'assignment_question_id');
    }
}
