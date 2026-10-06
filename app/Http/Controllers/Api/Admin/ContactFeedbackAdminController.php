<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactFeedbackSubmission;
use App\Support\ExcelDownload;
use App\Support\ExportDateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactFeedbackAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = max(1, (int) $request->query('page', 1));

        $paginator = ContactFeedbackSubmission::query()
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = collect($paginator->items())->map(static function (ContactFeedbackSubmission $row) {
            return [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'phone' => $row->phone,
                'message' => $row->message,
                'createdAt' => $row->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $validated = $request->validate(ExportDateRange::rules());
        [$from, $to] = ExportDateRange::extract($validated);

        $query = ContactFeedbackSubmission::query()->orderByDesc('id');
        ExportDateRange::constrain($query, $from, $to);

        $rows = $query->get()->map(fn (ContactFeedbackSubmission $row) => [
            $row->created_at?->format('Y-m-d H:i') ?? '',
            $row->name ?? '',
            $row->email ?? '',
            $row->phone ?? '',
            $row->message ?? '',
        ])->values()->all();

        return ExcelDownload::make(
            'Feedback',
            ['Date', 'Name', 'Email', 'Phone', 'Message'],
            $rows,
            'contact-feedback'
        );
    }
}
