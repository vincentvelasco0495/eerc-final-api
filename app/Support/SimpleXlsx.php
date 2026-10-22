<?php

namespace App\Support;

use ZipArchive;

final class SimpleXlsx
{
    private const HEADER_ROW = 2;

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, scalar|array<string, mixed>|null>>  $rows
     */
    public static function build(string $sheetName, array $headers, array $rows): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('PHP zip extension is required to export Excel files.');
        }

        $sheet = self::sanitizeSheetName($sheetName);
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'enrollments-'.bin2hex(random_bytes(8)).'.xlsx';

        $normalized = self::normalizeRows($headers, $rows);
        $images = $normalized['images'];
        $dataRows = $normalized['rows'];
        $imageColumns = $normalized['imageColumns'];
        $imageRows = $normalized['imageRows'];

        $zip = new ZipArchive();
        if (is_file($tmp)) {
            unlink($tmp);
        }
        $opened = $zip->open($tmp, ZipArchive::CREATE);
        if ($opened !== true) {
            throw new \RuntimeException('Could not create the Excel workbook.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes($images));
        $zip->addFromString('_rels/.rels', self::rels());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheet));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            self::worksheetXml($sheet, $headers, $dataRows, $imageColumns, $imageRows, $images !== [])
        );

        if ($images !== []) {
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', self::sheetRels());
            $zip->addFromString('xl/drawings/drawing1.xml', self::drawingXml($images));
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', self::drawingRels($images));
            foreach ($images as $index => $image) {
                $zip->addFromString('xl/media/image'.($index + 1).'.'.$image['ext'], $image['bytes']);
            }
        }

        $zip->close();

        $binary = file_get_contents($tmp);
        @unlink($tmp);

        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Could not read the Excel workbook.');
        }

        return $binary;
    }

    /**
     * @return array{bytes: string, ext: string, width: int, height: int}|null
     */
    public static function prepareImage(string $absolutePath, ?string $mime = null): ?array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        $info = @getimagesize($absolutePath);
        if (! is_array($info) || (int) ($info[0] ?? 0) < 1 || (int) ($info[1] ?? 0) < 1) {
            return null;
        }

        $type = (int) ($info[2] ?? 0);
        $ext = match ($type) {
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            default => null,
        };
        if ($ext === null) {
            return null;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $max = 120;
        $scale = min($max / max($width, 1), $max / max($height, 1), 1.0);
        $displayWidth = max(1, (int) round($width * $scale));
        $displayHeight = max(1, (int) round($height * $scale));

        $resized = self::resizedJpegBytes($absolutePath, $type, $width, $height, $displayWidth, $displayHeight);
        if ($resized !== null) {
            return [
                'bytes' => $resized,
                'ext' => 'jpeg',
                'width' => $displayWidth,
                'height' => $displayHeight,
            ];
        }

        $bytes = @file_get_contents($absolutePath);
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > 1_500_000) {
            return null;
        }

        return [
            'bytes' => $bytes,
            'ext' => $ext,
            'width' => $displayWidth,
            'height' => $displayHeight,
        ];
    }

    private static function resizedJpegBytes(
        string $absolutePath,
        int $type,
        int $width,
        int $height,
        int $nextWidth,
        int $nextHeight
    ): ?string {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagejpeg')) {
            return null;
        }

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolutePath),
            IMAGETYPE_PNG => @imagecreatefrompng($absolutePath),
            IMAGETYPE_GIF => @imagecreatefromgif($absolutePath),
            default => null,
        };
        if (! $src) {
            return null;
        }

        $dst = imagecreatetruecolor($nextWidth, $nextHeight);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nextWidth, $nextHeight, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nextWidth, $nextHeight, $width, $height);

        ob_start();
        imagejpeg($dst, null, 78);
        $bytes = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, scalar|array<string, mixed>|null>>  $rows
     * @return array{
     *   rows: list<list<string>>,
     *   images: list<array{row: int, col: int, bytes: string, ext: string, width: int, height: int}>,
     *   imageColumns: list<int>,
     *   imageRows: list<int>
     * }
     */
    private static function normalizeRows(array $headers, array $rows): array
    {
        $dataRows = [];
        $images = [];
        $imageColumns = [];
        $imageRows = [];

        foreach ($rows as $row) {
            $cells = [];
            $rowNumber = count($dataRows) + self::HEADER_ROW + 1;
            foreach ($headers as $index => $_header) {
                $cell = is_array($row) ? ($row[$index] ?? '') : '';
                if (is_array($cell) && isset($cell['image']['bytes']) && is_string($cell['image']['bytes'])) {
                    $images[] = [
                        'row' => $rowNumber,
                        'col' => $index,
                        'bytes' => $cell['image']['bytes'],
                        'ext' => (string) ($cell['image']['ext'] ?? 'jpeg'),
                        'width' => (int) ($cell['image']['width'] ?? 120),
                        'height' => (int) ($cell['image']['height'] ?? 120),
                    ];
                    $imageColumns[$index] = true;
                    $imageRows[$rowNumber] = true;
                    $cells[] = '';
                    continue;
                }

                if (is_array($cell)) {
                    $cells[] = (string) ($cell['v'] ?? '');
                    continue;
                }

                $cells[] = $cell;
            }
            $dataRows[] = $cells;
        }

        return [
            'rows' => $dataRows,
            'images' => $images,
            'imageColumns' => array_map('intval', array_keys($imageColumns)),
            'imageRows' => array_map('intval', array_keys($imageRows)),
        ];
    }

    private static function sanitizeSheetName(string $name): string
    {
        $clean = preg_replace('/[:\\\\\\/\\?\\*\\[\\]]/', ' ', $name) ?? 'Sheet1';
        $clean = trim($clean);
        if ($clean === '') {
            return 'Sheet1';
        }

        return mb_substr($clean, 0, 31);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, scalar|null>>  $rows
     * @param  list<int>  $imageColumns
     * @param  list<int>  $imageRows
     */
    private static function worksheetXml(
        string $sheetName,
        array $headers,
        array $rows,
        array $imageColumns,
        array $imageRows,
        bool $hasDrawings
    ): string {
        $lastCol = self::columnLetter(max(1, count($headers)));
        $lastRow = max(self::HEADER_ROW, count($rows) + self::HEADER_ROW);
        $imageColLookup = array_fill_keys($imageColumns, true);
        $imageRowLookup = array_fill_keys($imageRows, true);
        $exportedAt = now()->format('F j, Y');
        $title = $sheetName.' - Exported '.$exportedAt;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            .'<dimension ref="A1:'.$lastCol.$lastRow.'"/>'
            .'<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
            .'<pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/>'
            .'<selection pane="bottomLeft" activeCell="A3" sqref="A3"/>'
            .'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="18" defaultColWidth="14"/>'
            .self::colsXml($headers, $imageColLookup)
            .'<sheetData>';

        $xml .= '<row r="1" ht="28" customHeight="1">';
        $xml .= self::cellXml('A1', $title, 5);
        $xml .= '</row>';

        $xml .= '<row r="'.self::HEADER_ROW.'" ht="36" customHeight="1">';
        foreach ($headers as $index => $header) {
            $xml .= self::cellXml(self::columnLetter($index + 1).self::HEADER_ROW, (string) $header, 1);
        }
        $xml .= '</row>';

        $rowNumber = self::HEADER_ROW + 1;
        foreach ($rows as $rowIndex => $row) {
            $isImageRow = isset($imageRowLookup[$rowNumber]);
            $alt = $rowIndex % 2 === 1;
            $xml .= $isImageRow
                ? '<row r="'.$rowNumber.'" ht="118" customHeight="1">'
                : '<row r="'.$rowNumber.'" ht="22" customHeight="1">';

            foreach ($headers as $index => $_header) {
                $style = isset($imageColLookup[$index]) ? 4 : ($alt ? 3 : 2);
                $xml .= self::cellXml(
                    self::columnLetter($index + 1).$rowNumber,
                    (string) ($row[$index] ?? ''),
                    $style
                );
            }
            $xml .= '</row>';
            $rowNumber++;
        }

        $xml .= '</sheetData>'
            .'<autoFilter ref="A'.self::HEADER_ROW.':'.$lastCol.$lastRow.'"/>';

        if (count($headers) > 1) {
            $xml .= '<mergeCells count="1"><mergeCell ref="A1:'.$lastCol.'1"/></mergeCells>';
        }

        $xml .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            .'<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>';

        if ($hasDrawings) {
            $xml .= '<drawing r:id="rId1"/>';
        }

        return $xml.'</worksheet>';
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, true>  $imageColLookup
     */
    private static function colsXml(array $headers, array $imageColLookup): string
    {
        if ($headers === []) {
            return '';
        }

        $xml = '<cols>';
        foreach ($headers as $index => $header) {
            $col = $index + 1;
            $width = self::columnWidth((string) $header, isset($imageColLookup[$index]));
            $xml .= '<col min="'.$col.'" max="'.$col.'" width="'.$width.'" customWidth="1"/>';
        }

        return $xml.'</cols>';
    }

    private static function columnWidth(string $header, bool $isImage): string
    {
        if ($isImage) {
            return '22';
        }

        $preset = [
            'Enrollment ID' => 34,
            'Learner' => 20,
            'Email' => 28,
            'Phone' => 16,
            'School' => 22,
            'Program' => 24,
            'Application type' => 20,
            'Course' => 20,
            'Courses' => 32,
            'Status' => 12,
            'Submitted' => 14,
            'Full name' => 22,
            'Alias name' => 16,
            'Application school' => 24,
            'Gender' => 12,
            'Date of birth' => 14,
            'Contact number' => 16,
            'Home address' => 32,
            'Batch enrolled' => 18,
            'Mode of learning' => 18,
            'Branch' => 16,
            'Review schedule' => 20,
            'Honors / awards / discount' => 28,
            'Board exam experience' => 20,
            'Retaker attempts' => 16,
            'Downpayment' => 14,
            'Package' => 24,
            'Terms confirmation' => 16,
            'Profile picture' => 22,
            'Discount proof' => 22,
            'Retaker proof' => 22,
            'Payment proof' => 22,
            'Signature' => 22,
            'Total paid' => 12,
            'Unreviewed payments' => 18,
        ];

        if (isset($preset[$header])) {
            return (string) $preset[$header];
        }

        return (string) min(36, max(12, mb_strlen($header) + 4));
    }

    private static function cellXml(string $ref, string $text, int $style): string
    {
        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'
            .self::xml(self::cellText($text))
            .'</t></is></c>';
    }

    private static function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
        $text = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', '', $text) ?? '';
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = iconv('UTF-8', 'UTF-8//IGNORE', $text) ?: '';
        }

        if (strlen($text) > 32767) {
            return substr($text, 0, 32767);
        }

        return $text;
    }

    private static function columnLetter(int $index): string
    {
        $letter = '';
        $n = $index;
        while ($n > 0) {
            $n--;
            $letter = chr(65 + ($n % 26)).$letter;
            $n = intdiv($n, 26);
        }

        return $letter !== '' ? $letter : 'A';
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param  list<array{ext: string}>  $images
     */
    private static function contentTypes(array $images): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="jpeg" ContentType="image/jpeg"/>'
            .'<Default Extension="jpg" ContentType="image/jpeg"/>'
            .'<Default Extension="png" ContentType="image/png"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

        if ($images !== []) {
            $xml .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }

        return $xml.'</Types>';
    }

    private static function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView/></bookViews>'
            .'<sheets><sheet name="'.self::xml($sheetName).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private static function sheetRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
            .'</Relationships>';
    }

    /**
     * @param  list<array{row: int, col: int, bytes: string, ext: string, width: int, height: int}>  $images
     */
    private static function drawingXml(array $images): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">';

        foreach ($images as $index => $image) {
            $embedId = 'rId'.($index + 1);
            $name = 'Picture '.($index + 1);
            $col = (int) $image['col'];
            $row = (int) $image['row'] - 1;
            $nvId = $index + 2;
            $pad = 127000;

            $xml .= '<xdr:twoCellAnchor editAs="oneCell">'
                .'<xdr:from>'
                .'<xdr:col>'.$col.'</xdr:col><xdr:colOff>'.$pad.'</xdr:colOff>'
                .'<xdr:row>'.$row.'</xdr:row><xdr:rowOff>'.$pad.'</xdr:rowOff>'
                .'</xdr:from>'
                .'<xdr:to>'
                .'<xdr:col>'.($col + 1).'</xdr:col><xdr:colOff>0</xdr:colOff>'
                .'<xdr:row>'.($row + 1).'</xdr:row><xdr:rowOff>0</xdr:rowOff>'
                .'</xdr:to>'
                .'<xdr:pic>'
                .'<xdr:nvPicPr>'
                .'<xdr:cNvPr id="'.$nvId.'" name="'.self::xml($name).'"/>'
                .'<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr>'
                .'</xdr:nvPicPr>'
                .'<xdr:blipFill>'
                .'<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="'.$embedId.'"/>'
                .'<a:stretch><a:fillRect/></a:stretch>'
                .'</xdr:blipFill>'
                .'<xdr:spPr>'
                .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
                .'</xdr:spPr>'
                .'</xdr:pic>'
                .'<xdr:clientData/>'
                .'</xdr:twoCellAnchor>';
        }

        return $xml.'</xdr:wsDr>';
    }

    /**
     * @param  list<array{ext: string}>  $images
     */
    private static function drawingRels(array $images): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        foreach ($images as $index => $image) {
            $id = $index + 1;
            $xml .= '<Relationship Id="rId'.$id.'"'
                .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"'
                .' Target="../media/image'.$id.'.'.$image['ext'].'"/>';
        }

        return $xml.'</Relationships>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3">'
            .'<font><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="16"/><color rgb="FF001632"/><name val="Calibri"/><family val="2"/></font>'
            .'</fonts>'
            .'<fills count="5">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF001632"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF8E7"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border>'
            .'<left style="thin"><color rgb="FFD1D5DB"/></left>'
            .'<right style="thin"><color rgb="FFD1D5DB"/></right>'
            .'<top style="thin"><color rgb="FFD1D5DB"/></top>'
            .'<bottom style="thin"><color rgb="FFD1D5DB"/></bottom>'
            .'<diagonal/>'
            .'</border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="6">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment wrapText="1" vertical="center" horizontal="center"/>'
            .'</xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
            .'<alignment wrapText="1" vertical="center"/>'
            .'</xf>'
            .'<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment wrapText="1" vertical="center"/>'
            .'</xf>'
            .'<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment wrapText="1" vertical="center" horizontal="center"/>'
            .'</xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">'
            .'<alignment vertical="center"/>'
            .'</xf>'
            .'</cellXfs>'
            .'</styleSheet>';
    }
}
