<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Estate\PayrollRun;
use Illuminate\Support\Collection;
use RuntimeException;
use ZipArchive;

/**
 * The accountant's file: what was paid, and what came off it.
 *
 * A REAL XLSX, WRITTEN BY HAND. An .xlsx is a zip of XML parts, and the four
 * this builds are the whole of a valid single-sheet workbook.
 *
 * `maatwebsite/excel` IS a dependency of this project and is deliberately not
 * used here. It exists for the report builders, where a sheet is assembled from
 * a query and wants styling; this file is one static table, nine columns wide,
 * with no formula, no style and no second sheet — and for that shape the four
 * parts below are less to read, and less to get wrong, than the object model
 * and the configuration would be. A library would be the right answer the day
 * this workbook needs a second sheet.
 *
 * NUMBERS ARE NUMBERS, NOT TEXT. The amounts are written as `<v>` in numeric
 * cells, so the accountant who opens this can total a column. A spreadsheet
 * whose money arrives as strings is a CSV wearing an xlsx extension, and the
 * first thing anybody does with it is retype the figures.
 *
 * NO ACCOUNT NUMBERS. This file goes to an accountant and an auditor; the bank
 * details are the bank's file, and putting them in both would mean two documents
 * to control instead of one.
 */
class PayrollSummaryXlsx implements PayrollFileFormat
{
    public function key(): string
    {
        return 'summary';
    }

    public function label(): string
    {
        return 'Payroll summary (XLSX)';
    }

    public function description(): string
    {
        return 'What was paid and what came off it: gross, NIS, NHT, Education Tax, PAYE and net, one row per employee. It carries no bank account numbers: those are the bank\'s file.';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function filename(PayrollRun $run): string
    {
        return $run->reference.'-summary.xlsx';
    }

    public function build(PayrollRun $run, Collection $lines): string
    {
        $rows = [[
            'Employee', 'Job title', 'Gross', 'NIS', 'NHT', 'Education Tax', 'PAYE', 'Net',
        ]];

        foreach ($lines as $line) {
            $rows[] = [
                $line->employee->full_name ?? 'Employee',
                $line->employee->job_title ?? '',
                $line->gross_minor / 100,
                $line->nis_minor / 100,
                $line->nht_minor / 100,
                $line->education_tax_minor / 100,
                $line->paye_minor / 100,
                $line->net_minor / 100,
            ];
        }

        /*
         * A TOTAL ROW, summed here from the same lines the rows came from —
         * never a formula. A formula would recompute in the reader's copy from
         * whatever they had edited, and this file is a record of what the
         * estate paid, not a working model of it.
         */
        $rows[] = [
            'Total',
            '',
            $lines->sum('gross_minor') / 100,
            $lines->sum('nis_minor') / 100,
            $lines->sum('nht_minor') / 100,
            $lines->sum('education_tax_minor') / 100,
            $lines->sum('paye_minor') / 100,
            $lines->sum('net_minor') / 100,
        ];

        return $this->workbook($run->period_label, $rows);
    }

    /**
     * The four parts of a minimal workbook, zipped.
     *
     * @param  list<list<string|float|int>>  $rows
     */
    private function workbook(string $sheetName, array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'gsxlsx');

        if ($file === false) {
            throw new RuntimeException('Could not open a temporary file to build the workbook.');
        }

        $zip = new ZipArchive;

        if ($zip->open($file, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not build the workbook.');
        }

        $zip->addFromString('[Content_Types].xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
            <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
            <Default Extension="xml" ContentType="application/xml"/>
            <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
            <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
            </Types>
            XML);

        $zip->addFromString('_rels/.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
            </Relationships>
            XML);

        // The sheet name is the period label, escaped: a workbook tab reading
        // "August 2026" is what an accountant filing twelve of these needs.
        $zip->addFromString('xl/workbook.xml', sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '.
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
            '<sheets><sheet name="%s" sheetId="1" r:id="rId1"/></sheets></workbook>',
            htmlspecialchars(substr($sheetName, 0, 31), ENT_QUOTES | ENT_XML1),
        ));

        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
            </Relationships>
            XML);

        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($rows));

        $zip->close();

        $contents = (string) file_get_contents($file);
        unlink($file);

        return $contents;
    }

    /**
     * The sheet itself.
     *
     * Inline strings (`t="inlineStr"`) rather than a shared-strings part: a
     * shared string table saves bytes on a workbook that repeats text, and this
     * one repeats almost none. One fewer part is one fewer thing to get wrong.
     *
     * @param  list<list<string|float|int>>  $rows
     */
    private function sheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $r => $row) {
            $xml .= '<row r="'.($r + 1).'">';

            foreach ($row as $c => $value) {
                $ref = $this->column($c).($r + 1);

                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';

                    continue;
                }

                $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'
                    .htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1)
                    .'</t></is></c>';
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    /** 0 to A, 25 to Z, 26 to AA — the spreadsheet's own bijective base-26. */
    private function column(int $index): string
    {
        $name = '';

        for ($n = $index; $n >= 0; $n = intdiv($n, 26) - 1) {
            $name = chr(65 + $n % 26).$name;
        }

        return $name;
    }
}
