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
     * Pure online and blended learning get every LMS tab.
     * Face to face may use quiz, handouts, and group study (not lecture videos).
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
        if (static::looksLikeBlendedClass($publicId, $name) || static::looksLikeFaceToFaceClass($publicId, $name)) {
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

    public static function looksLikeFaceToFaceClass(string $publicId, string $name): bool
    {
        if (static::looksLikeBlendedClass($publicId, $name)) {
            return false;
        }

        $id = strtolower(trim($publicId));
        $label = strtolower(trim($name));
        if (preg_match('/face[\s-]*to[\s-]*face/', $id) || preg_match('/face[\s-]*to[\s-]*face/', $label)) {
            return true;
        }
        if (preg_match('/\bf2f\b/', $id) || preg_match('/\bf2f\b/', $label)) {
            return true;
        }

        return $id === 'learning-mode-face' || str_ends_with($id, '-face');
    }

    public static function looksLikeDigitalLessonAccess(string $publicId, string $name): bool
    {
        return static::digitalAccessTier($publicId, $name) !== 'none';
    }

    /**
     * `full` — Pure online class and blended learning (all LMS tabs).
     * `classroom` — Face to face (quiz / handouts / group study; no lecture video).
     * `none` — Unrecognized.
     */
    public static function digitalAccessTier(string $publicId, string $name): string
    {
        if (static::looksLikeBlendedClass($publicId, $name) || static::looksLikeOnlineClass($publicId, $name)) {
            return 'full';
        }
        if (static::looksLikeFaceToFaceClass($publicId, $name)) {
            return 'classroom';
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

        $candidates = [$publicId];
        if ($publicId === 'learning-mode-face') {
            $candidates[] = 'learning-mode-face-to-face';
        } elseif ($publicId === 'learning-mode-face-to-face') {
            $candidates[] = 'learning-mode-face';
        }

        $id = static::query()->whereIn('public_id', $candidates)->value('id');

        return $id ? (int) $id : null;
    }
}
