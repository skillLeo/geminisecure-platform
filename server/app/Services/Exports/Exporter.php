<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Models\Estate\Document;
use App\Services\Audit\AuditLogger;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every export in this system, and the entry each one writes (12 §1).
 *
 * THE RULING: "every export writes an audit entry — actor, scope, row count,
 * timestamp; cross-tenant exports additionally name the tenants included. That
 * audit entry IS the trail the reason asked for."
 *
 * Several inert controls carried a reason saying an export "leaves the estate as
 * a file naming who owes what, and needs a retention rule before it needs a
 * button". The ruling answers it: a file that has left cannot be retained,
 * recalled or deleted by this system, and pretending otherwise would be the
 * worse lie. What CAN be true is that the estate knows a file left, who took it,
 * what was in it and when — and that is what every call here records, BEFORE a
 * byte is streamed, so an export that fails halfway is still on the record.
 *
 * EVERY EXPORT COMES THROUGH HERE. Not because the CSV writing is hard, but
 * because the audit entry is the point: a second export path that wrote its own
 * file would be an export with no trail, and the one that forgets is the one
 * that matters.
 */
class Exporter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Stream a CSV, having first recorded that it left.
     *
     * @param  string  $scope  what was exported, in words a reader will recognise a year from now — "Arrears register, all phases"
     * @param  list<string>  $headers
     * @param  list<array<int, string|int|float|null>>  $rows  materialised, not a generator: the row count goes in the audit entry, and a count nobody knows is not a trail
     * @param  list<string>|null  $tenants  named only by a cross-tenant export, per the ruling
     */
    public function csv(string $scope, array $headers, array $rows, string $filename, ?array $tenants = null): StreamedResponse
    {
        $this->record($scope, count($rows), $filename, $tenants);

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            /*
             * A UTF-8 BOM, and it is not decoration. Excel on Windows — which is
             * what a Jamaican strata office opens a file in — reads a BOM-less
             * UTF-8 CSV as the system codepage, and every accented name in the
             * register arrives mangled. Three bytes fixes it, and nothing else
             * that reads CSV minds them.
             */
            fwrite($out, "\xEF\xBB\xBF");

            /*
             * NO CELL IS A FORMULA (B074). A claimant's name, a household's name, an
             * actor's name — text strangers typed — reach these files, and Excel runs a
             * cell that starts with `=` as code. Every cell goes through
             * `SpreadsheetCell`, headers included, so no export can be the one that
             * forgot. The escape is the empty string: RFC 4180 quoting, which is how
             * Excel reads the file and what PHP's default is becoming.
             */
            fputcsv($out, SpreadsheetCell::row($headers), escape: '');

            foreach ($rows as $row) {
                fputcsv($out, SpreadsheetCell::row($row), escape: '');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Stream bytes somebody else built — a bank file, a spreadsheet — with the
     * same entry against it.
     *
     * @param  list<string>|null  $tenants
     * @param  Document|null  $kept  the retained copy, named in the entry so the trail leads to the file that left (13 A2)
     */
    public function file(string $scope, string $contents, string $filename, string $contentType, int $rowCount, ?array $tenants = null, ?Document $kept = null): StreamedResponse
    {
        $this->record($scope, $rowCount, $filename, $tenants, $kept === null ? [] : [
            'document_id' => $kept->id,
            'kind' => $kept->kind,
            'sha256' => $kept->sha256,
        ]);

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $filename, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * A document asked for, or a document fetched (13 A3).
     *
     * "The ruling was every export, and a download is an export." Both ends are
     * recorded and told apart by `stage`: a request is somebody causing a
     * document to exist, a download is the file leaving, and a trail that
     * merged the two could not say whether a statement asked for in March was
     * ever taken away. The count is one document, the unit a reader will
     * recognise — a statement's own lines are not rows anybody exported.
     *
     * @param  'requested'|'downloaded'  $stage
     */
    public function document(Document $document, string $stage): void
    {
        $this->record(
            scope: $document->title,
            rows: 1,
            filename: $document->filename,
            tenants: null,
            extra: [
                'stage' => $stage,
                'document_id' => $document->id,
                'kind' => $document->kind,
                'sha256' => $document->sha256,
            ],
        );
    }

    /**
     * The entry itself. Actor, scope, row count, timestamp — and the tenants
     * where more than one estate's data is in the file.
     *
     * THE ACTOR AND THE TIMESTAMP ARE THE LOGGER'S, not arguments: an export
     * that could name its own actor could name somebody else's.
     *
     * @param  list<string>|null  $tenants
     * @param  array<string, mixed>  $extra
     */
    private function record(string $scope, int $rows, string $filename, ?array $tenants, array $extra = []): void
    {
        $after = [
            'scope' => $scope,
            'rows' => $rows,
            'file' => $filename,
            ...$extra,
        ];

        /*
         * NAMED ONLY WHEN THERE IS MORE THAN ONE. A single-estate export already
         * says which estate in the entry's own `tenant_id`, and repeating it
         * here would make the two look like different claims.
         */
        if ($tenants !== null) {
            $after['tenants'] = $tenants;
            $after['tenant_count'] = count($tenants);
        }

        $this->audit->record(
            action: 'export.taken',
            entityType: 'Export',
            entityId: null,
            after: $after,
        );
    }
}
