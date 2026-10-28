<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseLibraryFile;
use App\Models\CourseLibraryFolder;
use App\Models\CourseLibraryTab;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LmsCourseLibraryService
{
    public const MAX_FILE_KB = 10240;

    public const TAB_TYPES = [
        'quiz',
        'handouts',
        'lecture-video',
        'group-study',
        'folder',
    ];

    public const DEFAULT_TABS = [
        ['type' => 'quiz', 'title' => 'Quiz', 'slug' => 'quiz'],
        ['type' => 'handouts', 'title' => 'Handouts', 'slug' => 'handouts'],
        ['type' => 'lecture-video', 'title' => 'Lecture video', 'slug' => 'lecture-video'],
        ['type' => 'group-study', 'title' => 'Group study', 'slug' => 'group-study'],
    ];

    public const RESERVED_SLUGS = [
        'curriculum',
        'notice',
        'faq',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function tabsPayload(Course $course): array
    {
        if (! Schema::hasTable('course_library_tabs')) {
            return [];
        }

        $this->ensureTabSchema();
        $this->ensureDefaultTabs($course);
        $course->unsetRelation('libraryTabs');
        $course->load(['libraryTabs.folders.files']);

        return $course->libraryTabs->map(fn (CourseLibraryTab $tab) => $this->formatTab($tab))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatTab(CourseLibraryTab $tab): array
    {
        $tab->loadMissing(['folders.files']);

        return [
            'id' => $tab->public_id,
            'key' => $tab->slug,
            'title' => $tab->title,
            'type' => $this->normalizeTabType($tab->tab_type),
            'isDefault' => false,
            'sortOrder' => (int) $tab->sort_order,
            'folders' => $this->folderTree($tab->folders, null),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CourseLibraryFolder>  $all
     * @return list<array<string, mixed>>
     */
    public function folderTree($all, ?int $parentId): array
    {
        return $all
            ->filter(fn (CourseLibraryFolder $folder) => (int) ($folder->parent_id ?? 0) === (int) ($parentId ?? 0))
            ->map(fn (CourseLibraryFolder $folder) => $this->formatFolder($folder, $all))
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CourseLibraryFolder>|null  $all
     * @return array<string, mixed>
     */
    public function formatFolder(CourseLibraryFolder $folder, $all = null): array
    {
        $folder->loadMissing(['files', 'parent']);
        $siblings = $all ?? collect([$folder]);

        return [
            'id' => $folder->public_id,
            'parentId' => $folder->parent?->public_id,
            'name' => $folder->name,
            'sortOrder' => (int) $folder->sort_order,
            'files' => $folder->files->map(fn (CourseLibraryFile $file) => $this->formatFile($file))->values()->all(),
            'folders' => $this->folderTree($siblings, $folder->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatFile(CourseLibraryFile $file): array
    {
        return [
            'id' => $file->public_id,
            'name' => $file->original_name,
            'mime' => $file->mime,
            'sizeBytes' => (int) $file->size_bytes,
            'kind' => $this->fileKind($file->mime, $file->original_name),
        ];
    }

    public function createTab(Course $course, string $title, string $type = 'folder'): CourseLibraryTab
    {
        $this->ensureTabSchema();
        $cleanTitle = trim($title);
        $slug = $this->uniqueSlug($course, $cleanTitle);
        $maxSort = (int) CourseLibraryTab::query()->where('course_id', $course->id)->max('sort_order');

        return CourseLibraryTab::query()->create([
            'public_id' => 'tab-'.Str::lower(Str::ulid()),
            'course_id' => $course->id,
            'title' => $cleanTitle,
            'slug' => $slug,
            'tab_type' => $this->normalizeTabType($type),
            'sort_order' => $maxSort + 1,
        ]);
    }

    public function ensureTabSchema(): void
    {
        if (! Schema::hasTable('course_library_tabs')) {
            return;
        }

        if (! Schema::hasColumn('course_library_tabs', 'tab_type')) {
            Schema::table('course_library_tabs', function (Blueprint $table) {
                $table->string('tab_type', 32)->default('folder')->after('slug');
            });
        }

        if (Schema::hasTable('courses') && ! Schema::hasColumn('courses', 'library_tabs_seeded')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->boolean('library_tabs_seeded')->default(false);
            });
        }
    }

    public function ensureDefaultTabs(Course $course): void
    {
        if (! Schema::hasColumn('courses', 'library_tabs_seeded') || $this->isSeeded($course->library_tabs_seeded)) {
            return;
        }

        DB::transaction(function () use ($course) {
            $locked = Course::query()->whereKey($course->id)->lockForUpdate()->first();
            if ($locked === null || $this->isSeeded($locked->library_tabs_seeded)) {
                return;
            }

            $existing = CourseLibraryTab::query()
                ->where('course_id', $locked->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($existing as $index => $tab) {
                $tab->sort_order = 10 + $index;
                if (! is_string($tab->tab_type) || $tab->tab_type === '') {
                    $tab->tab_type = 'folder';
                }
                $tab->save();
            }

            $takenTypes = $existing->pluck('tab_type')->filter()->all();
            foreach (self::DEFAULT_TABS as $index => $definition) {
                if (in_array($definition['type'], $takenTypes, true)) {
                    continue;
                }
                CourseLibraryTab::query()->create([
                    'public_id' => 'tab-'.Str::lower(Str::ulid()),
                    'course_id' => $locked->id,
                    'title' => $definition['title'],
                    'slug' => $this->uniqueSlug($locked, $definition['slug']),
                    'tab_type' => $definition['type'],
                    'sort_order' => $index + 1,
                ]);
            }

            $locked->library_tabs_seeded = true;
            $locked->save();
        });

        $course->library_tabs_seeded = true;
    }

    private function isSeeded(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function normalizeTabType(mixed $type): string
    {
        $value = strtolower(trim((string) $type));

        return in_array($value, self::TAB_TYPES, true) ? $value : 'folder';
    }

    public function uniqueSlug(Course $course, string $title, ?int $ignoreTabId = null): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'materials';
        }

        $slug = $base;
        $i = 2;
        while (
            in_array($slug, self::RESERVED_SLUGS, true)
            || CourseLibraryTab::query()
                ->where('course_id', $course->id)
                ->when($ignoreTabId, fn ($q) => $q->where('id', '!=', $ignoreTabId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    public function storeFile(CourseLibraryFolder $folder, UploadedFile $uploaded, ?int $userId): CourseLibraryFile
    {
        $folder->loadMissing('tab');
        $path = Storage::disk('public')->putFile(
            'course-library/'.$folder->tab->course_id.'/'.$folder->id,
            $uploaded
        );

        return CourseLibraryFile::query()->create([
            'public_id' => 'libfile-'.Str::lower(Str::ulid()),
            'folder_id' => $folder->id,
            'original_name' => $uploaded->getClientOriginalName() ?: ('file-'.$uploaded->hashName()),
            'storage_path' => $path,
            'mime' => $uploaded->getMimeType(),
            'size_bytes' => (int) $uploaded->getSize(),
            'uploaded_by' => $userId && $userId > 0 ? $userId : null,
        ]);
    }

    public function deleteFile(CourseLibraryFile $file): void
    {
        if (is_string($file->storage_path) && $file->storage_path !== '') {
            Storage::disk('public')->delete($file->storage_path);
        }
        $file->delete();
    }

    public function deleteTab(CourseLibraryTab $tab): void
    {
        $tab->loadMissing('folders.files');
        foreach ($tab->folders as $folder) {
            foreach ($folder->files as $file) {
                $this->deleteFile($file);
            }
        }
        $tab->delete();
    }

    public function deleteFolder(CourseLibraryFolder $folder): void
    {
        $folder->loadMissing(['files', 'children']);
        foreach ($folder->children as $child) {
            $this->deleteFolder($child);
        }
        foreach ($folder->files as $file) {
            $this->deleteFile($file);
        }
        $folder->delete();
    }

    public function folderDepth(CourseLibraryFolder $folder): int
    {
        $depth = 1;
        $current = $folder;
        while ($current->parent_id) {
            $current = $current->parent;
            if ($current === null) {
                break;
            }
            $depth++;
            if ($depth > 12) {
                break;
            }
        }

        return $depth;
    }

    private function fileKind(?string $mime, string $name): string
    {
        $mime = strtolower((string) $mime);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
            return 'image';
        }
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return 'pdf';
        }

        return 'file';
    }
}
