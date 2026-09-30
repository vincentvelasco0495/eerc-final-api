<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BranchEnroll extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'name',
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

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'branch_enroll_id');
    }

    /**
     * Resolve a branch by its unique public ID. Never matches by display name.
     */
    public static function resolveId(array $formData): ?int
    {
        $publicId = trim((string) ($formData['branchEnrollId'] ?? ''));
        if ($publicId === '') {
            return null;
        }

        $id = static::query()->where('public_id', $publicId)->value('id');

        return $id ? (int) $id : null;
    }
}
