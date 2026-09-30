<?php

namespace App\Support;

use ZipArchive;

final class SimpleXlsx
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, scalar|null>>  $rows
     */
    public static function build(string $sheetName, array $headers, array $rows): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('PHP zip extension is required to export Excel files.');
        }

        $sheet = self::sanitizeSheetName($sheetName);
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'enrollments-'.bin2hex(random_bytes(8)).'.xlsx';

        $zip = new ZipArchive();
        if (is_file($tmp)) {
            unlink($tmp);
        }
        $opened = $zip->open($tmp, ZipArchive::CREATE);
        if ($opened !== true) {
            throw new \RuntimeException('Could not create the Excel workbook.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rels());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheet));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::worksheetXml($headers, $rows));
        $zip->close();

        $binary = file_get_contents($tmp);
        @unlink($tmp);

        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Could not read the Excel workbook.');
        }

        return $binary;
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
     */
    private static function worksheetXml(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>';

        $xml .= self::rowXml(1, $headers);

        $rowNumber = 2;
        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $index => $_header) {
                $cells[] = $row[$index] ?? '';
            }
            $xml .= self::rowXml($rowNumber, $cells);
            $rowNumber++;
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    /**
     * @param  array<int, scalar|null>  $values
     */
    private static function rowXml(int $rowNumber, array $values): string
    {
        $xml = '<row r="'.$rowNumber.'">';
        foreach ($values as $index => $value) {
            $col = self::columnLetter($index + 1);
            $text = self::cellText($value);
            $xml .= '<c r="'.$col.$rowNumber.'" t="inlineStr"><is><t xml:space="preserve">'
                .self::xml($text)
                .'</t></is></c>';
        }

        return $xml.'</row>';
    }

    private static function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
        $text = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', '', $text) ?? '';

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
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
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

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            .'</styleSheet>';
    }
}
