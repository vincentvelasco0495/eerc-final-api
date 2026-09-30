<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonMaterial extends Model
{
    protected $fillable = [
        'public_id',
        'module_id',
        'module_resource_id',
        'assignment_id',
        'original_name',
        'storage_path',
        'mime',
        'size_bytes',
        'usage',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_id');
    }

    public function moduleResource(): BelongsTo
    {
        return $this->belongsTo(ModuleResource::class, 'module_resource_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    public function isQuizQuestionImage(): bool
    {
        if (strtolower(trim((string) ($this->usage ?? 'lesson'))) === 'quiz_question_image') {
            return true;
        }

        $path = str_replace('\\', '/', (string) $this->storage_path);

        return str_contains($path, 'lesson-materials/quiz-questions/');
    }
}
