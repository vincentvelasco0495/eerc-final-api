<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $fillable = [
        'public_id',
        'course_id',
        'title',
        'subject',
        'topic',
        'subtopic',
        'learning_flow_step',
        'duration_label',
        'sort_order',
        'is_visible',
        'streaming_only',
        'summary',
        'excerpt_html',
        'body_html',
        'lesson_meta_json',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'streaming_only' => 'boolean',
            'lesson_meta_json' => 'array',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(ModuleResource::class, 'module_id')->orderBy('sort_order');
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class, 'module_id')->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'module_id')->orderBy('sort_order');
    }

    /**
     * Files stored with a `module_id` (core + in-module resource–tagged); excludes standalone
     * rows that use `module_resource_id` only.
     */
    public function moduleLessonMaterials(): HasMany
    {
        return $this->hasMany(LessonMaterial::class, 'module_id');
    }

    /** Files attached to the module’s primary (-core) text lesson row only (no resource link). */
    public function lessonCoreMaterials(): HasMany
    {
        return $this->hasMany(LessonMaterial::class, 'module_id')->whereNull('module_resource_id');
    }
}
