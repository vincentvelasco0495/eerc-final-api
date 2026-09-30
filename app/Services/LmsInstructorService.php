<?php

namespace App\Services;

use App\Models\CmsMedia;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LmsInstructorService
{
    /** @return array<string, mixed> */
    public function formatInstructor(Instructor $instructor): array
    {
        $instructor->loadMissing('user');
        $user = $instructor->user;
        if (! $user) {
            throw new \RuntimeException('Instructor row is missing linked user.');
        }

        return [
            'id' => $user->public_uid,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status ?? 'active',
            'achievements' => $instructor->achievements,
            'displayName' => $instructor->display_name ?: $user->name,
            'position' => $instructor->position,
            'bio' => $instructor->bio,
            'facebook' => $instructor->facebook,
            'linkedin' => $instructor->linkedin,
            'twitter' => $instructor->twitter,
            'instagram' => $instructor->instagram,
            'profilePath' => $instructor->profile_path,
            'profileUrl' => $this->resolveMediaDeliveryUrl($instructor->profile_path, $user->id),
            'coverPath' => $instructor->cover_path,
            'coverUrl' => $this->resolveMediaDeliveryUrl($instructor->cover_path, $user->id),
        ];
    }

    private function resolveMediaDeliveryUrl(?string $value, ?int $uploadedBy = null): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $raw)) {
            $path = parse_url($raw, PHP_URL_PATH);
            if (! is_string($path) || trim($path) === '') {
                return $raw;
            }
            if (str_starts_with($path, '/api/media/')) {
                return $path;
            }
            $raw = $path;
        }

        if (str_starts_with($raw, '/api/media/')) {
            return $raw;
        }

        $normalized = str_replace('\\', '/', $raw);
        if (str_starts_with($normalized, '/storage/')) {
            $normalized = substr($normalized, 9);
        }
        $normalized = ltrim($normalized, '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            return null;
        }

        $disk = Storage::disk('public')->exists($normalized)
            ? 'public'
            : (Storage::disk('local')->exists($normalized) ? 'local' : null);
        if ($disk === null) {
            return null;
        }

        $media = CmsMedia::query()
            ->where('disk', $disk)
            ->where('path', $normalized)
            ->first();

        if ($media === null) {
            $media = CmsMedia::query()->create([
                'public_id' => 'media-'.Str::lower(Str::ulid()),
                'uploaded_by' => $uploadedBy,
                'disk' => $disk,
                'path' => $normalized,
                'url' => '/storage/'.$normalized,
                'filename' => basename($normalized),
                'original_name' => basename($normalized),
                'mime' => null,
                'size_bytes' => 0,
                'alt' => null,
            ]);
        }

        return '/api/media/'.$media->public_id.'/file';
    }

    /** @return array<int, array<string, mixed>> */
    public function instructors(): array
    {
        return Instructor::query()
            ->with('user')
            ->join('users', 'users.id', '=', 'instructors.user_id')
            ->orderBy('users.name')
            ->select('instructors.*')
            ->get()
            ->map(fn (Instructor $i) => $this->formatInstructor($i))
            ->all();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function instructorsPaginated(int $page, int $perPage, ?string $search = null): array
    {
        $query = Instructor::query()
            ->with('user')
            ->join('users', 'users.id', '=', 'instructors.user_id')
            ->select([
                'instructors.id',
                'instructors.user_id',
                'instructors.achievements',
                'instructors.display_name',
                'instructors.position',
                'instructors.bio',
                'instructors.facebook',
                'instructors.linkedin',
                'instructors.twitter',
                'instructors.instagram',
                'instructors.profile_path',
                'instructors.cover_path',
                'instructors.created_at',
                'instructors.updated_at',
            ])
            ->orderBy('users.name')
            ->orderByDesc('instructors.id');

        $term = $search !== null ? trim($search) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->where('instructors.achievements', 'like', $like)
                    ->orWhere('users.name', 'like', $like)
                    ->orWhere('users.email', 'like', $like)
                    ->orWhere('users.status', 'like', $like);
            });
        }

        /** @var LengthAwarePaginator<int, Instructor> $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->getCollection()
                ->map(fn (Instructor $i) => $this->formatInstructor($i))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
        ];
    }
}
