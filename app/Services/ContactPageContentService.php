<?php

namespace App\Services;

use App\Models\ContactPageSection;
use Illuminate\Support\Collection;

class ContactPageContentService
{
    public const SECTION_KEYS = [
        'details',
        'feedback',
        'representative',
    ];

    /**
     * @return array{sections: array<string, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function publicPayload(bool $includeDraft = false): array
    {
        $query = ContactPageSection::query()->orderBy('sort_order');

        if (! $includeDraft) {
            $query->where('status', 'published');
        }

        $rows = $query->get();

        return [
            'sections' => $this->mapSections($rows, false, ! $includeDraft),
            'meta' => [
                'updatedAt' => $this->latestUpdatedAt($rows),
            ],
        ];
    }

    /**
     * @return array{sections: array<string, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function adminPayload(): array
    {
        $rows = ContactPageSection::query()->orderBy('sort_order')->get();

        return [
            'sections' => $this->mapSections($rows, true, false),
            'meta' => [
                'updatedAt' => $this->latestUpdatedAt($rows),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function updateSection(string $sectionKey, array $content, ?string $status = null): array
    {
        $key = strtolower(trim($sectionKey));

        if (! in_array($key, self::SECTION_KEYS, true)) {
            abort(404, 'Unknown contact page section.');
        }

        $row = ContactPageSection::query()->where('section_key', $key)->first();

        if ($row === null) {
            abort(404, 'Contact page section not found.');
        }

        $row->content_json = $content;
        if ($status !== null && in_array($status, ['draft', 'published'], true)) {
            $row->status = $status;
        }
        $row->save();

        return $this->formatSectionRow($row, true);
    }

    public function publishAll(): void
    {
        ContactPageSection::query()->update(['status' => 'published']);
    }

    /**
     * @param  Collection<int, ContactPageSection>  $rows
     * @return array<string, array<string, mixed>>
     */
    protected function mapSections(Collection $rows, bool $includeMeta = false, bool $respectVisible = true): array
    {
        $out = [];

        foreach ($rows as $row) {
            $content = $row->content_json ?? [];
            if (! is_array($content)) {
                $content = [];
            }

            if ($respectVisible && ! ($content['visible'] ?? true)) {
                continue;
            }

            $out[$row->section_key] = $includeMeta
                ? $this->formatSectionRow($row, true)
                : $content;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatSectionRow(ContactPageSection $row, bool $includeMeta): array
    {
        $content = $row->content_json ?? [];

        if (! $includeMeta) {
            return is_array($content) ? $content : [];
        }

        return [
            'sectionKey' => $row->section_key,
            'title' => $row->title,
            'status' => $row->status,
            'sortOrder' => (int) $row->sort_order,
            'content' => is_array($content) ? $content : [],
            'updatedAt' => $row->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, ContactPageSection>  $rows
     */
    protected function latestUpdatedAt(Collection $rows): ?string
    {
        $latest = $rows->max('updated_at');

        return $latest?->toIso8601String();
    }
}
