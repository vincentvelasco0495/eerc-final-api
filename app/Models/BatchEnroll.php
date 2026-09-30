<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BatchEnroll extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'program_id',
        'code',
        'label',
        'name',
        'tentative_start',
        'description',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'batch_enroll_id');
    }

    /**
     * Resolve a batch by its unique public ID for the given program. Never matches by name.
     */
    public static function resolveIdForProgram(int $programId, array $formData): ?int
    {
        $publicId = trim((string) ($formData['batchEnrollId'] ?? ''));
        if ($publicId === '') {
            return null;
        }

        $id = static::query()
            ->where('public_id', $publicId)
            ->where('program_id', $programId)
            ->value('id');

        return $id ? (int) $id : null;
    }
}
