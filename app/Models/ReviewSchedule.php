<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReviewSchedule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'name',
        'description',
        'status',
        'sort_order',
        'enrollment_branch_id',
        'student_capacity',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'enrollment_branch_id' => 'integer',
            'student_capacity' => 'integer',
        ];
    }

    public function branchEnroll(): BelongsTo
    {
        return $this->belongsTo(BranchEnroll::class, 'enrollment_branch_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'review_schedule_id');
    }

    public function getNameAttribute(?string $value): ?string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        $label = $this->attributes['label'] ?? null;

        return $label !== null && $label !== '' ? $label : null;
    }

    /**
     * Resolve a schedule by unique public ID and verify its associated branch.
     * Never matches by schedule label, time, or branch name.
     */
    public static function resolveId(array $formData): ?int
    {
        $publicId = trim((string) ($formData['reviewScheduleId'] ?? ''));
        if ($publicId === '') {
            return null;
        }

        $schedule = static::query()
            ->where('public_id', $publicId)
            ->first(['id', 'enrollment_branch_id']);
        if (! $schedule) {
            return null;
        }

        $branchPublicId = trim((string) ($formData['branchEnrollId'] ?? ''));
        if ($branchPublicId !== '') {
            $branchId = BranchEnroll::query()->where('public_id', $branchPublicId)->value('id');
            if (! $branchId || (int) $schedule->enrollment_branch_id !== (int) $branchId) {
                return null;
            }
        }

        return (int) $schedule->id;
    }
}
