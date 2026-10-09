<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use RuntimeException;
use ZipArchive;

/**
 * Écrit un classeur Excel (.xlsx) minimal : une feuille par tableau, en-têtes en gras, nombres typés (jamais du texte).
 * Aucune dépendance : un .xlsx est une archive zip de quelques fichiers XML.
 *
 * Types de colonne : text, int, number, pct, money, hours.
 */
final class Xlsx
{
    /** Index de style dans xl/styles.xml. */
    private const STYLE = ['text' => 0, 'header' => 1, 'int' => 2, 'number' => 3, 'pct' => 4, 'money' => 5, 'hours' => 6, 'title' => 7];

    /**
     * @param  list<array{name: string, title: string, columns: list<array{label: string, type: string}>, rows: list<list<int|float|string|null>>}>  $sheets
     */
    public static function write(array $sheets, string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer le classeur.');
        }

        $names = [];
        $overrides = '';
        $sheetRefs = '';
        $relations = '';

        foreach ($sheets as $index => $sheet) {
            $number = $index + 1;
            $names[] = $name = self::sheetName($sheet['name'], $names);
            $zip->addFromString("xl/worksheets/sheet{$number}.xml", self::sheet($sheet));
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$number}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $sheetRefs .= '<sheet name="'.self::escape($name)."\" sheetId=\"{$number}\" r:id=\"rId{$number}\"/>";
            $relations .= "<Relationship Id=\"rId{$number}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$number}.xml\"/>";
        }

        $styleId = count($sheets) + 1;
        $relations .= "<Relationship Id=\"rId{$styleId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>";

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$overrides.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheetRefs.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->close();
    }

    /**
     * @param  array{name: string, title: string, columns: list<array{label: string, type: string}>, rows: list<list<int|float|string|null>>}  $sheet
     */
    private static function sheet(array $sheet): string
    {
        $rows = '<row r="1">'.self::cell('A1', $sheet['title'], 'title').'</row>';
        $line = 3;
        $header = '';

        foreach ($sheet['columns'] as $column => $definition) {
            $header .= self::cell(self::ref($column, $line), $definition['label'], 'header');
        }

        $rows .= "<row r=\"{$line}\">{$header}</row>";

        foreach ($sheet['rows'] as $row) {
            $line++;
            $cells = '';

            foreach ($row as $column => $value) {
                $cells .= self::cell(self::ref($column, $line), $value, $sheet['columns'][$column]['type'] ?? 'text');
            }

            $rows .= "<row r=\"{$line}\">{$cells}</row>";
        }

        $widths = '';

        foreach ($sheet['columns'] as $column => $definition) {
            $width = $column === 0 ? 34 : max(12, min(28, mb_strlen($definition['label']) + 3));
            $widths .= '<col min="'.($column + 1).'" max="'.($column + 1).'" width="'.$width.'" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>'.$widths.'</cols><sheetData>'.$rows.'</sheetData></worksheet>';
    }

    private static function cell(string $ref, int|float|string|null $value, string $type): string
    {
        $style = self::STYLE[$type] ?? 0;

        if ($value === null || $value === '') {
            return "<c r=\"{$ref}\" s=\"{$style}\"/>";
        }

        if (is_int($value) || is_float($value)) {
            return "<c r=\"{$ref}\" s=\"{$style}\"><v>".(is_float($value) ? rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') : $value).'</v></c>';
        }

        return "<c r=\"{$ref}\" s=\"{$style}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">".self::escape($value).'</t></is></c>';
    }

    private static function ref(int $column, int $row): string
    {
        $letters = '';

        for ($n = $column; $n >= 0; $n = intdiv($n, 26) - 1) {
            $letters = chr(65 + $n % 26).$letters;
        }

        return $letters.$row;
    }

    /**
     * @param  list<string>  $taken
     */
    private static function sheetName(string $name, array $taken): string
    {
        $clean = trim((string) preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name));
        $clean = mb_substr($clean === '' ? 'Sheet' : $clean, 0, 28);
        $candidate = $clean;

        for ($i = 2; in_array($candidate, $taken, true); $i++) {
            $candidate = mb_substr($clean, 0, 26).' '.$i;
        }

        return $candidate;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3"><numFmt numFmtId="164" formatCode="0.0"/><numFmt numFmtId="165" formatCode="#,##0.00"/><numFmt numFmtId="166" formatCode="0.00"/></numFmts>'
            .'<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE6F2EE"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="8">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment wrapText="1" vertical="center"/></xf>'
            .'<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
