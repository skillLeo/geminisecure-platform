<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Exports\Exporter;
use App\Services\Platform\MailLog;
use App\Support\PlatformCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Access and audit log, Super Admin screen 41.
 *
 * Read-only, and it offers no way to change anything — not even a disabled
 * one. A greyed-out delete button says deletion is a thing that could be
 * switched on, and for this table it cannot be: audit_log is append-only at a
 * withheld grant, at a database trigger and in App\Models\AuditEntry. So this
 * screen has no edit control, no delete control, and no route that would
 * accept one.
 *
 * Everything the reader can change — the search, the three filters, the sort
 * and the page — is a GET parameter, resolved here and applied in the query.
 * That makes any view of the log a URL somebody else can be sent, which is
 * what an audit trail is usually being read for.
 *
 * Requested parameters are sanitised rather than validated. A hand-edited or
 * stale URL should show the log it can make sense of, with the filters it
 * understood named above the table, rather than bounce the reader to an error.
 */
class AuditLogController extends Controller
{
    /**
     * The ceiling on one export, and it is a ceiling rather than a page.
     *
     * An audit log grows forever, and a file with a hundred thousand rows in it
     * is one nothing on a strata office's desk will open. The date filters are
     * how a reader narrows it, and the scope recorded on the entry says what
     * they asked for — so a truncated file is visibly a filtered one.
     */
    private const EXPORT_MAX_ROWS = 10_000;

    public function __invoke(Request $request, AuditLogger $log): Response
    {
        $user = $request->user();

        $filters = [
            'q' => $this->text($request, 'q'),
            'actor' => $this->text($request, 'actor'),
            'category' => $this->text($request, 'category'),
            'from' => $this->date($request, 'from'),
            'to' => $this->date($request, 'to'),
        ];

        /*
         * A site-scoped role sees entries for its estates, plus platform-level
         * entries that concern no single estate. Passed into the query, never
         * applied to its result: sorting, filtering or paging past the list
         * must not widen what it can reach.
         */
        $estateIds = $user->widestScope() === AccessScope::AssignedSites
            ? $user->accessibleEstateIds()
            : null;

        // M32 F5: the order, its way, the page and the page size are the shared table's, by its one rule.
        $result = $log->page($filters, AuditLogger::table($request), $estateIds);

        return inertia('Gemini/Audit/Index', [
            'rows' => $result['rows'],
            'columns' => AuditLogger::columns(),
            'filters' => $filters,
            'table' => $result['table'],
            'basePath' => route('gemini.access_audit_log', [], false),
        ]);
    }

    /**
     * The filtered log as a file — board 44's Export (12 §1).
     *
     * CROSS-TENANT BY NATURE, so this is the export the ruling's second
     * sentence is about: the entry names the estates whose rows are in the
     * file. A site-scoped role exports its own estates and the platform-level
     * entries, exactly what it can read on screen — the scope is passed into
     * the query, never applied to its result.
     *
     * THE AUDIT LOG EXPORTING ITSELF WRITES AN AUDIT ENTRY, which is not a
     * curiosity: taking a copy of who did what is itself a thing somebody did,
     * and it is the one act that would otherwise leave no trace.
     */
    /**
     * EVERY EMAIL SENT, AND WHAT BECAME OF IT (M18G §7) — for the Director and the Operations Manager, who both read
     * this module. Searchable by address: the first place to look when somebody says "I never got the code".
     */
    public function emails(Request $request): Response
    {
        // Every send of the sixty days kept, a page at a time, in the order its headings ask for (M32 F5).
        $table = MailLog::table($request);
        ['rows' => $sent, 'table' => $meta] = MailLog::page($table);

        return inertia('Gemini/Audit/Emails', [
            'sent' => $sent,
            'address' => $table->search,
            'table' => $meta,
        ]);
    }

    public function export(Request $request, AuditLogger $log, Exporter $exporter): StreamedResponse
    {
        $user = $request->user();

        $filters = [
            'q' => $this->text($request, 'q'),
            'actor' => $this->text($request, 'actor'),
            'category' => $this->text($request, 'category'),
            'from' => $this->date($request, 'from'),
            'to' => $this->date($request, 'to'),
        ];

        $estateIds = $user->widestScope() === AccessScope::AssignedSites
            ? $user->accessibleEstateIds()
            : null;

        $result = $log->search(
            $filters,
            AuditLogger::sortKey($this->text($request, 'sort')),
            AuditLogger::direction($this->text($request, 'dir')),
            $estateIds,
            perPage: self::EXPORT_MAX_ROWS,
        );

        $rows = array_map(static fn (array $row): array => [
            $row['timestamp'],
            $row['actor'],
            $row['category'],
            $row['estate'],
            $row['details'],
        ], $result['rows']);

        // The estates actually in the file, named because this export reaches
        // across them. Derived from the rows rather than from the viewer's
        // scope: the scope is what they MAY see, and the entry records what
        // they took.
        $tenants = array_values(array_unique(array_column($result['rows'], 'estate')));
        sort($tenants);

        $narrowed = array_filter($filters, static fn (?string $value): bool => $value !== null);

        return $exporter->csv(
            scope: 'Access audit log'.($narrowed === [] ? ', unfiltered' : ', filtered: '.http_build_query($narrowed)),
            // Each row is on its own estate's clock and says which (M11 §4.3); the header says so too.
            headers: ['When (each estate\'s own time)', 'Who', 'Category', 'Estate', 'What happened'],
            rows: $rows,
            filename: 'audit-log-'.PlatformCalendar::today()->format('Y-m-d').'.csv',
            tenants: $tenants,
        );
    }

    /** A trimmed, length-capped query parameter, or null when it is absent or empty. */
    private function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, 120, '');
    }

    /**
     * A calendar date, or null.
     *
     * Checked for shape and for existence rather than parsed. It names a day,
     * which `AuditLogger` turns into each estate's own bounds for that day (a
     * row is filed under the date it shows, on its estate's clock — B016), so a
     * well-formed real date is all that is needed, and a parser here would raise
     * on '2026-02-31' in a pasted URL that should simply be ignored.
     */
    private function date(Request $request, string $key): ?string
    {
        $value = $this->text($request, $key);

        if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }
}
