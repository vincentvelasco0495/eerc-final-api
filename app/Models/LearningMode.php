<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LearningMode extends Model
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

    public function getNameAttribute(?string $value): ?string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        $label = $this->attributes['label'] ?? null;

        return $label !== null && $label !== '' ? $label : null;
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'learning_mode_id');
    }

    /**
     * PURE ONLINE CLASS (not face-to-face, not blended).
     */
    public function isOnlineClass(): bool
    {
        return static::looksLikeOnlineClass(
            (string) $this->public_id,
            (string) ($this->name ?? '')
        );
    }

    public static function looksLikeOnlineClass(string $publicId, string $name): bool
    {
        $id = strtolower(trim($publicId));
        $label = strtolower(trim($name));
        if (str_contains($id, 'blended') || str_contains($label, 'blended')) {
            return false;
        }
        if (preg_match('/face[\s-]*to[\s-]*face/', $id) || preg_match('/face[\s-]*to[\s-]*face/', $label)) {
            return false;
        }

        return str_contains($id, 'online') || str_contains($label, 'online');
    }

    /**
     * Resolve a learning mode by its unique public ID. Never matches by display name.
     */
    public static function resolveId(array $formData): ?int
    {
        $publicId = trim((string) ($formData['learningModeId'] ?? ''));
        if ($publicId === '') {
            return null;
        }

        $id = static::query()->where('public_id', $publicId)->value('id');

        return $id ? (int) $id : null;
    }
}
