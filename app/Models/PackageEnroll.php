<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PackageEnroll extends Model
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
        return $this->hasMany(Enrollment::class, 'package_enroll_id');
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
     * Resolve by unique public ID. Never matches by display name or similar wording.
     */
    public static function resolveId(array $formData): ?int
    {
        $publicId = trim((string) ($formData['packageEnrollId'] ?? $formData['package_enroll_id'] ?? ''));
        if ($publicId === '') {
            return null;
        }

        $id = static::query()->where('public_id', $publicId)->value('id');

        return $id ? (int) $id : null;
    }
}
