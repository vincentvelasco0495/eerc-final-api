<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Course extends Model
{
    protected $fillable = [
        'public_id',
        'program_id',
        'slug',
        'title',
        'mentor_display_name',
        'description',
        'marketing_json',
        'level',
        'total_modules',
        'hours',
        'course_fee',
        'learners_count',
        'next_module_id',
        'video_hours_label',
        'preview_completed',
        'is_published',
        'average_rating',
    ];

    protected function casts(): array
    {
        return [
            'preview_completed' => 'boolean',
            'is_published' => 'boolean',
            'average_rating' => 'decimal:2',
            'marketing_json' => 'array',
            'course_fee' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id')->withTrashed();
    }

    public function nextModule(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'next_module_id');
    }

    public function modules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Module::class, 'course_id')->orderBy('sort_order');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'course_tag', 'course_id', 'tag_id');
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'course_subject', 'course_id', 'subject_id');
    }

    public function quizzes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Quiz::class, 'course_id');
    }
}
