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

    /**
     * PURE ONLINE CLASS or BLENDED LEARNING may use LMS lesson tabs.
     * FACE TO FACE CLASS cannot.
     */
    public function grantsDigitalLessonAccess(): bool
    {
        return static::looksLikeDigitalLessonAccess(
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

    public static function looksLikeBlendedClass(string $publicId, string $name): bool
    {
        $id = strtolower(trim($publicId));
        $label = strtolower(trim($name));

        return str_contains($id, 'blended') || str_contains($label, 'blended');
    }

    public static function looksLikeDigitalLessonAccess(string $publicId, string $name): bool
    {
        return static::digitalAccessTier($publicId, $name) !== 'none';
    }

    /**
     * `full` — Pure online class (all LMS tabs).
     * `replay` — Blended learning (lecture video / replay only).
     * `none` — Face to face, or unrecognized.
     */
    public static function digitalAccessTier(string $publicId, string $name): string
    {
        if (static::looksLikeBlendedClass($publicId, $name)) {
            return 'replay';
        }
        if (static::looksLikeOnlineClass($publicId, $name)) {
            return 'full';
        }

        return 'none';
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
