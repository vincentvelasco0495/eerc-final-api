<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModuleResource extends Model
{
    protected $fillable = [
        'public_id',
        'module_id',
        'title',
        'lesson_kind',
        'is_standalone_lesson',
        'summary',
        'excerpt_html',
        'body_html',
        'lesson_meta_json',
        'format',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_standalone_lesson' => 'boolean',
            'lesson_meta_json' => 'array',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_id');
    }

    public function lessonMaterials(): HasMany
    {
        return $this->hasMany(LessonMaterial::class, 'module_resource_id');
    }
}
