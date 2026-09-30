<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HonorAwardDiscount extends Model
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
        return $this->hasMany(Enrollment::class, 'honor_award_discount_id');
    }

    /**
     * Resolve by unique public ID. Never matches by display name, and never maps empty/null to "None".
     */
    public static function resolveId(array $formData): ?int
    {
        $publicId = trim((string) ($formData['honorAwardDiscountId'] ?? ''));
        if ($publicId === '') {
            return null;
        }

        $id = static::query()->where('public_id', $publicId)->value('id');

        return $id ? (int) $id : null;
    }
}
