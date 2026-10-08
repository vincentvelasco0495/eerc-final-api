<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'course_id',
        'module_id',
        'title',
        'content_html',
        'duration_minutes',
        'attempts_allowed',
        'settings_json',
        'sort_order',
        'question_count',
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
        return $this->hasMany(AssignmentQuestion::class, 'assignment_id')->orderBy('sort_order');
    }

    public function lessonMaterials(): HasMany
    {
        return $this->hasMany(LessonMaterial::class, 'assignment_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AssignmentAttempt::class, 'assignment_id');
    }

    /** Hide rows whose course or module has been soft-deleted. */
    public function scopeOnLiveCurriculum($query)
    {
        return $query->whereHas('course')->where(function ($nested) {
            $nested->whereNull('module_id')->orWhereHas('module');
        });
    }
}
