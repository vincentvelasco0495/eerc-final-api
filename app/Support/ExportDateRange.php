<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class ExportDateRange
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0:?string, 1:?string}
     */
    public static function extract(array $validated): array
    {
        $from = isset($validated['from']) ? substr(trim((string) $validated['from']), 0, 10) : '';
        $to = isset($validated['to']) ? substr(trim((string) $validated['to']), 0, 10) : '';
        $from = $from !== '' ? $from : null;
        $to = $to !== '' ? $to : null;

        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    public static function constrain(Builder $query, ?string $from, ?string $to, string $column = 'created_at'): Builder
    {
        if ($from) {
            $query->whereDate($column, '>=', $from);
        }
        if ($to) {
            $query->whereDate($column, '<=', $to);
        }

        return $query;
    }

    public static function constrainCoalesce(
        Builder $query,
        ?string $from,
        ?string $to,
        string $primary = 'submitted_at',
        string $fallback = 'created_at'
    ): Builder {
        $expr = 'DATE(COALESCE('.$primary.', '.$fallback.'))';
        if ($from) {
            $query->whereRaw($expr.' >= ?', [$from]);
        }
        if ($to) {
            $query->whereRaw($expr.' <= ?', [$to]);
        }

        return $query;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function filterAssocRows(array $rows, ?string $from, ?string $to, string $key): array
    {
        if (! $from && ! $to) {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($from, $to, $key) {
            $raw = substr(trim((string) ($row[$key] ?? '')), 0, 10);
            if ($raw === '' || $raw === '—' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                return false;
            }
            if ($from && $raw < $from) {
                return false;
            }
            if ($to && $raw > $to) {
                return false;
            }

            return true;
        }));
    }
}
