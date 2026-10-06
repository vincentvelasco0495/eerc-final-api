<?php

namespace App\Support;

use Illuminate\Http\Response;

class ExcelDownload
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, scalar|null>>  $rows
     */
    public static function make(string $sheetName, array $headers, array $rows, string $filenameBase): Response
    {
        $binary = SimpleXlsx::build($sheetName, $headers, $rows);
        $filename = preg_replace('/[^\w.-]+/', '-', $filenameBase).'-'.now()->format('Y-m-d').'.xlsx';

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public static function plain(?string $value): string
    {
        return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
