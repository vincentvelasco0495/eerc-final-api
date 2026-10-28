<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseLibraryFile;
use App\Models\CourseLibraryFolder;
use App\Models\CourseLibraryTab;
use App\Services\LmsCatalogService;
use App\Services\LmsCourseLibraryService;
use App\Support\RangedFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LmsCourseLibraryController extends Controller
{
    use ResolvesLmsActor;

    public function index(string $coursePublicId, LmsCourseLibraryService $library, LmsCatalogService $catalog): JsonResponse
    {
        $course = $this->findCourse($coursePublicId);
        $this->assertCanView($catalog, $course);

        return response()->json(['data' => $library->tabsPayload($course)]);
    }

    public function storeTab(Request $request, string $coursePublicId, LmsCourseLibraryService $library): JsonResponse
    {
        $this->assertStaff();
        $course = $this->findCourse($coursePublicId);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'type' => ['required', 'string', 'in:'.implode(',', LmsCourseLibraryService::TAB_TYPES)],
        ]);

        $tab = $library->createTab($course, $data['title'], $data['type']);

        return response()->json(['data' => $library->formatTab($tab)], 201);
    }

    public function updateTab(Request $request, string $publicId, LmsCourseLibraryService $library): JsonResponse
    {
        $this->assertStaff();
        $tab = CourseLibraryTab::query()->with('course')->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'type' => ['required', 'string', 'in:'.implode(',', LmsCourseLibraryService::TAB_TYPES)],
        ]);

        $tab->title = trim($data['title']);
        $tab->tab_type = $library->normalizeTabType($data['type']);
        $tab->slug = $library->uniqueSlug($tab->course, $tab->title, $tab->id);
        $tab->save();

        return response()->json(['data' => $library->formatTab($tab->fresh())]);
    }

    public function destroyTab(string $publicId, LmsCourseLibraryService $library): JsonResponse
    {
        $this->assertStaff();
        $tab = CourseLibraryTab::query()->where('public_id', $publicId)->firstOrFail();
        $library->deleteTab($tab);

        return response()->json(['message' => 'Tab deleted.']);
    }

    public function storeFolder(Request $request, string $publicId, LmsCourseLibraryService $library): JsonResponse
    {
        $this->assertStaff();
        $tab = CourseLibraryTab::query()->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parentId' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $parent = null;
        $parentPublicId = isset($data['parentId']) ? trim((string) $data['parentId']) : '';
        if ($parentPublicId !== '') {
            $parent = CourseLibraryFolder::query()
                ->where('public_id', $parentPublicId)
                ->where('tab_id', $tab->id)
                ->first();
            if ($parent === null) {
                return response()->json(['message' => 'Parent folder was not found in this tab.'], 422);
            }
            if ($library->folderDepth($parent) >= 8) {
                return response()->json(['message' => 'Folders can only be nested 8 levels deep.'], 422);
            }
        }

        $maxSort = (int) CourseLibraryFolder::query()
            ->where('tab_id', $tab->id)
            ->where('parent_id', $parent?->id)
            ->max('sort_order');
        $folder = CourseLibraryFolder::query()->create([
            'public_id' => 'folder-'.Str::lower(Str::ulid()),
            'tab_id' => $tab->id,
            'parent_id' => $parent?->id,
            'name' => trim($data['name']),
            'sort_order' => $maxSort + 1,
        ]);

        return response()->json(['data' => $library->formatFolder($folder)], 201);
    }

    public function updateFolder(Request $request, string $publicId, LmsCourseLibraryService $library): JsonResponse
    {
        $this->assertStaff();
        $folder = CourseLibraryFolder::query()->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $folder->name = trim($data['name']);
        $folder->save();

        return response()->json(['data' => $library->formatFolder($folder)]);
    }

    public function destroyFolder(string $publicId, LmsCourseLibraryService $library): JsonResponse
    {
        $this->assertStaff();
        $folder = CourseLibraryFolder::query()->where('public_id', $publicId)->firstOrFail();
        $library->deleteFolder($folder);

        return response()->json(['message' => 'Folder deleted.']);
    }

    public function storeFile(Request $request, string $publicId, LmsCourseLibraryService $library): JsonResponse
    {
        $this->assertStaff();
        $folder = CourseLibraryFolder::query()->with('tab')->where('public_id', $publicId)->firstOrFail();
        if (! $request->hasFile('file')) {
            return response()->json([
                'message' => 'No file was received. Try a file of 10 MB or less.',
            ], 422);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:'.LmsCourseLibraryService::MAX_FILE_KB],
        ]);

        $file = $library->storeFile($folder, $request->file('file'), (int) $this->lmsActor()->id);

        return response()->json(['data' => $library->formatFile($file)], 201);
    }

    public function destroyFile(string $publicId, LmsCourseLibraryService $library): JsonResponse
    {
        $this->assertStaff();
        $file = CourseLibraryFile::query()->where('public_id', $publicId)->firstOrFail();
        $library->deleteFile($file);

        return response()->json(['message' => 'File deleted.']);
    }

    public function download(Request $request, string $publicId, LmsCatalogService $catalog): BinaryFileResponse|\Illuminate\Http\Response
    {
        $file = CourseLibraryFile::query()->with('folder.tab.course')->where('public_id', $publicId)->firstOrFail();
        $this->assertCanView($catalog, $file->folder->tab->course);

        $disk = Storage::disk('public');
        if (! $disk->exists($file->storage_path)) {
            return response()->json(['message' => 'File missing on storage.'], 404);
        }

        $mime = is_string($file->mime) && trim($file->mime) !== ''
            ? trim($file->mime)
            : 'application/octet-stream';
        $inline = $request->boolean('inline') || str_starts_with(strtolower($mime), 'image/');

        return RangedFileResponse::make(
            $disk->path($file->storage_path),
            $mime,
            $inline,
            $file->original_name,
            ltrim((string) $file->storage_path, '/')
        );
    }

    private function findCourse(string $coursePublicId): Course
    {
        return Course::query()->where('public_id', $coursePublicId)->firstOrFail();
    }

    private function assertStaff(): void
    {
        $role = strtolower((string) ($this->lmsActor()->role ?? ''));
        if (! in_array($role, ['admin', 'instructor'], true)) {
            abort(403, 'Forbidden.');
        }
    }

    private function assertCanView(LmsCatalogService $catalog, Course $course): void
    {
        $actor = $this->lmsActor();
        $role = strtolower((string) ($actor->role ?? ''));
        if (in_array($role, ['admin', 'instructor'], true)) {
            return;
        }
        if (! $catalog->userCanAccessCourseLessons($actor, $course)) {
            abort(403, 'Forbidden.');
        }
    }
}
