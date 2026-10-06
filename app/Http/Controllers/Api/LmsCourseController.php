<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\CmsMedia;
use App\Models\Course;
use App\Models\Program;
use App\Models\UserModuleProgress;
use App\Services\LmsCatalogService;
use App\Support\HttpCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LmsCourseController extends Controller
{
    use ResolvesLmsActor;

    public function index(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $program = trim((string) $request->query('program', ''));
        $status = strtolower(trim((string) $request->query('status', '')));
        $allowedStatuses = ['published', 'draft', 'upcoming'];
        $statusFilter = in_array($status, $allowedStatuses, true) ? $status : null;

        $user = $this->lmsActor();
        $payload = $catalog->coursesPaginated(
            $user,
            $page,
            $limit,
            $program !== '' ? $program : null,
            $statusFilter
        );

        return response()->json($payload);
    }

    public function show(string $courseLookup, LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor();
        $lookup = trim(urldecode($courseLookup));
        $normalized = strtolower($lookup);

        $course = Course::query()
            ->with(['program', 'tags', 'subjects', 'nextModule', 'modules'])
            ->where('public_id', $lookup)
            ->orWhereRaw('LOWER(slug) = ?', [$normalized])
            ->first();

        if ($course === null && $normalized !== '') {
            // Fallback for stale links when a slug was later de-duplicated to `${slug}-2`, etc.
            $course = Course::query()
                ->with(['program', 'tags', 'subjects', 'nextModule', 'modules'])
                ->whereRaw('LOWER(slug) LIKE ?', [$normalized.'-%'])
                ->orderByDesc('updated_at')
                ->first();
        }

        if ($course === null) {
            abort(404, 'Course not found.');
        }

        $completedMods = UserModuleProgress::query()
            ->where('user_id', $user->id)
            ->where('progress_percent', '>=', 100)
            ->pluck('module_id');

        return response()->json([
            'data' => $catalog->formatCourse($course, $user, $completedMods),
        ]);
    }

    public function stats(string $coursePublicId, LmsCatalogService $catalog): JsonResponse|Response
    {
        return HttpCache::json([
            'data' => $catalog->courseStats($coursePublicId),
        ], 90, true);
    }

    /**
     * Create a new catalog course (instructor authoring). Program defaults to the first program
     * when `programId` is omitted. Slug is generated from the title and made unique.
     */
    public function store(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor();

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:512'],
            'programId' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $title = isset($validated['title']) ? trim((string) $validated['title']) : '';
        if ($title === '') {
            $title = 'Untitled course';
        }

        $programHint = isset($validated['programId']) ? trim((string) $validated['programId']) : '';
        $program = Program::query()
            ->when($programHint !== '', fn ($q) => $q->where('public_id', $programHint))
            ->orderBy('id')
            ->first();

        if ($program === null) {
            $program = Program::query()->orderBy('id')->firstOrFail();
        }

        $baseSlug = Str::slug($title);
        if ($baseSlug === '') {
            $baseSlug = 'course';
        }

        $slug = $baseSlug;
        $n = 2;
        while (Course::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$n;
            $n++;
        }

        $course = Course::query()->create([
            'public_id' => (string) Str::uuid(),
            'program_id' => $program->id,
            'slug' => $slug,
            'title' => $title,
            'mentor_display_name' => $user->name,
            'description' => null,
            'marketing_json' => [],
            'is_published' => false,
        ]);

        LmsCatalogService::bustUserAnalyticsCache($user->id);

        $completedMods = UserModuleProgress::query()
            ->where('user_id', $user->id)
            ->where('progress_percent', '>=', 100)
            ->pluck('module_id');

        $fresh = $course->fresh(['program', 'tags', 'subjects', 'nextModule', 'modules']);

        return response()->json([
            'data' => $catalog->formatCourse($fresh, $user, $completedMods),
        ], 201);
    }

    /** Partial update (`courses.description`, scalar fields, and merge into `courses.marketing_json`). */
    public function update(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor();

        /** @var Course $course */
        $course = Course::query()->where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:512'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:191'],
            'programId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string'],
            'mentor' => ['sometimes', 'nullable', 'string', 'max:191'],
            'level' => ['sometimes', 'nullable', 'string', 'max:64'],
            'hours' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'courseFee' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'videoHoursLabel' => ['sometimes', 'nullable', 'string', 'max:191'],
            'marketing' => ['sometimes', 'array'],
            'marketingPayload' => ['sometimes', 'nullable', 'string'],
            'bannerImage' => ['sometimes', 'nullable', 'image', 'max:4096'],
            'isPublished' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['title'])) {
            $course->title = trim($validated['title']);
        }

        if (array_key_exists('slug', $validated)) {
            $incomingSlug = trim((string) ($validated['slug'] ?? ''));
            if ($incomingSlug !== '') {
                $baseSlug = Str::slug($incomingSlug);
                if ($baseSlug === '') {
                    $baseSlug = 'course';
                }
                $resolvedSlug = $baseSlug;
                $n = 2;
                while (Course::query()
                    ->where('slug', $resolvedSlug)
                    ->where('id', '!=', $course->id)
                    ->exists()) {
                    $resolvedSlug = $baseSlug.'-'.$n;
                    $n++;
                }
                $course->slug = $resolvedSlug;
            }
        }

        if (array_key_exists('programId', $validated)) {
            $programHint = trim((string) ($validated['programId'] ?? ''));
            if ($programHint !== '') {
                $program = Program::query()->where('public_id', $programHint)->first();
                if ($program !== null) {
                    $course->program_id = $program->id;
                }
            }
        }

        if (array_key_exists('description', $validated)) {
            $course->description = $validated['description'] !== null ? trim((string) $validated['description']) : null;
        }

        if (array_key_exists('mentor', $validated)) {
            $course->mentor_display_name = $validated['mentor'] !== null ? trim((string) $validated['mentor']) : null;
        }

        if (array_key_exists('level', $validated)) {
            $course->level = $validated['level'] !== null ? trim((string) $validated['level']) : null;
        }

        if (isset($validated['hours'])) {
            $course->hours = (int) $validated['hours'];
        }

        if (isset($validated['courseFee'])) {
            $course->course_fee = (int) $validated['courseFee'];
        }

        if (array_key_exists('videoHoursLabel', $validated)) {
            $course->video_hours_label = $validated['videoHoursLabel'] !== null
                ? trim((string) $validated['videoHoursLabel'])
                : null;
        }

        if (array_key_exists('isPublished', $validated)) {
            $course->is_published = (bool) $validated['isPublished'];
        }

        $incomingMarketing = null;
        if (isset($validated['marketing']) && is_array($validated['marketing'])) {
            $incomingMarketing = $validated['marketing'];
        } elseif (array_key_exists('marketingPayload', $validated)) {
            $decoded = json_decode((string) $validated['marketingPayload'], true);
            if (is_array($decoded)) {
                $incomingMarketing = $decoded;
            }
        }

        if (is_array($incomingMarketing)) {
            $incoming = $incomingMarketing;
            $base = $course->marketing_json ?? [];

            if (! array_key_exists('description', $incoming) && array_key_exists('paragraphs', $incoming)) {
                $incoming['description'] = $incoming['paragraphs'];
            }

            foreach (['description', 'notices'] as $listKey) {
                if (array_key_exists($listKey, $incoming)) {
                    $base[$listKey] = self::sanitizeStringArray($incoming[$listKey]);
                }
            }

            if (array_key_exists('description', $incoming)) {
                unset($base['paragraphs']);
            }
            unset($base['audience'], $base['learningOutcomes'], $base['learn']);

            if (array_key_exists('coInstructors', $incoming)) {
                $base['coInstructors'] = self::sanitizeStringArray($incoming['coInstructors']);
            }

            if (array_key_exists('faq', $incoming)) {
                $base['faq'] = self::sanitizeFaqArray($incoming['faq']);
            }

            unset($base['featuredCourse']);

            if (array_key_exists('lockLessonsInOrder', $incoming)) {
                $boolValue = filter_var($incoming['lockLessonsInOrder'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($boolValue === null) {
                    unset($base['lockLessonsInOrder']);
                } else {
                    $base['lockLessonsInOrder'] = (bool) $boolValue;
                }
            }

            if (array_key_exists('noticeHeading', $incoming)) {
                $h = self::sanitizeOptionalString($incoming['noticeHeading']);
                if ($h === null || $h === '') {
                    unset($base['noticeHeading']);
                } else {
                    $base['noticeHeading'] = $h;
                }
            }

            if (array_key_exists('bannerImageUrl', $incoming)) {
                $u = self::sanitizeOptionalStringUrl($incoming['bannerImageUrl']);
                if ($u === null || $u === '') {
                    unset($base['bannerImageUrl']);
                    unset($base['bannerImageMediaId']);
                } else {
                    $base['bannerImageUrl'] = self::normalizeBannerStoragePath($u) ?? $u;
                    unset($base['heroImageUrl']);
                }
            }

            if (array_key_exists('bannerImageMediaId', $incoming)) {
                $id = self::sanitizeOptionalString($incoming['bannerImageMediaId']);
                if ($id === null || $id === '') {
                    unset($base['bannerImageMediaId']);
                } else {
                    $base['bannerImageMediaId'] = mb_substr($id, 0, 64);
                }
            }

            if (array_key_exists('heroImageUrl', $incoming)) {
                $u = self::sanitizeOptionalStringUrl($incoming['heroImageUrl']);
                if ($u === null || $u === '') {
                    unset($base['heroImageUrl']);
                } else {
                    $base['heroImageUrl'] = $u;
                }
            }

            $course->marketing_json = $base;
        }

        if ($request->hasFile('bannerImage')) {
            $base = $course->marketing_json ?? [];
            $previousMediaId = isset($base['bannerImageMediaId']) && is_string($base['bannerImageMediaId'])
                ? trim((string) $base['bannerImageMediaId'])
                : '';
            $previous = isset($base['bannerImageUrl']) && is_string($base['bannerImageUrl'])
                ? trim((string) $base['bannerImageUrl'])
                : '';

            if ($previousMediaId !== '') {
                $oldMedia = CmsMedia::query()->where('public_id', $previousMediaId)->first();
                if ($oldMedia !== null) {
                    if ($oldMedia->disk && $oldMedia->path && Storage::disk($oldMedia->disk)->exists($oldMedia->path)) {
                        Storage::disk($oldMedia->disk)->delete($oldMedia->path);
                    }
                    $oldMedia->delete();
                }
            }

            if ($previous !== '' && ! str_starts_with($previous, 'http')) {
                $normalizedPrevious = self::normalizeBannerStoragePath($previous) ?? $previous;
                $toDelete = ltrim($normalizedPrevious, '/');
                if ($toDelete !== '') {
                    Storage::disk('public')->delete($toDelete);
                }
            }

            $stored = Storage::disk('public')->putFile('course-banners', $request->file('bannerImage'));
            $media = CmsMedia::query()->create([
                'public_id' => 'media-'.Str::lower(Str::ulid()),
                'uploaded_by' => $user->id,
                'disk' => 'public',
                'path' => $stored,
                'url' => '/storage/'.ltrim($stored, '/'),
                'filename' => basename($stored),
                'original_name' => $request->file('bannerImage')->getClientOriginalName() ?: basename($stored),
                'mime' => $request->file('bannerImage')->getMimeType(),
                'size_bytes' => (int) $request->file('bannerImage')->getSize(),
                'alt' => null,
            ]);
            $base['bannerImageUrl'] = $stored;
            $base['bannerImageMediaId'] = $media->public_id;
            unset($base['heroImageUrl']);
            $course->marketing_json = $base;
        }

        $course->save();

        LmsCatalogService::bustUserAnalyticsCache($user->id);

        $completedMods = UserModuleProgress::query()
            ->where('user_id', $user->id)
            ->where('progress_percent', '>=', 100)
            ->pluck('module_id');

        $fresh = $course->fresh(['program', 'tags', 'subjects', 'nextModule', 'modules']);

        return response()->json([
            'data' => $catalog->formatCourse($fresh, $user, $completedMods),
        ]);
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private static function sanitizeStringArray($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $s = is_string($item) ? trim($item) : '';
            if ($s !== '') {
                $out[] = mb_substr($s, 0, 65000);
            }
        }

        return $out;
    }

    /** @param  mixed  $value */
    private static function sanitizeFaqArray($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $q = isset($row['question']) ? trim((string) $row['question']) : '';
            $a = isset($row['answer']) ? trim((string) $row['answer']) : '';

            $out[] = [
                'question' => mb_substr($q, 0, 2000),
                'answer' => mb_substr($a, 0, 65000),
            ];
        }

        return $out;
    }

    /** @param  mixed  $value */
    private static function sanitizeOptionalString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : mb_substr($s, 0, 2000);
    }

    /** @param  mixed  $value */
    private static function sanitizeOptionalStringUrl($value): ?string
    {
        $s = self::sanitizeOptionalString($value);
        if ($s === null || $s === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $s)) {
            return mb_substr($s, 0, 2048);
        }

        if (str_starts_with($s, '/') && ! str_starts_with($s, '//')) {
            return mb_substr($s, 0, 2048);
        }

        return mb_substr($s, 0, 2048);
    }

    private static function normalizeBannerStoragePath(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '/storage/')) {
            return ltrim(substr($trimmed, 9), '/');
        }

        if (preg_match('#^https?://#i', $trimmed)) {
            $path = parse_url($trimmed, PHP_URL_PATH);
            if (! is_string($path) || trim($path) === '') {
                return null;
            }
            $path = trim($path);
            if (str_starts_with($path, '/storage/')) {
                return ltrim(substr($path, 9), '/');
            }
            return ltrim($path, '/');
        }

        return ltrim($trimmed, '/');
    }
}
