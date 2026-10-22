<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Estate\Settings;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * THE ESTATE'S AUDIT LOG, READ ONLY (M84 §3.2; SET-2: Settings promised one and there was no page).
 *
 * This estate's entries and nothing else: when, who, their role, what they did, what it was done to, before and
 * after; filtered by person, date range and module; paged; exported. Only entries under an estate module the viewer
 * can see: Gemini's own work at the estate stays on the Gemini console's log (`AuditLogger::ESTATE_MODULE_OF`). No
 * action here writes: the table is append only in the database, and the page offers no edit, delete or bulk action to
 * any role. Behind its switch (`estate_features.audit_log`, M84 §0.1): off, Settings shows no link, and every address
 * here answers with a page that says the audit log is switched off (Hassam, 12 Oct: a switched-off module's deep link
 * shows a clean page saying so, never the bare "Not found ... worth reporting" it drew until then).
 */
final class AuditLogController extends Controller
{
    public function index(Request $request, AuditLogger $log, Settings $settings): Response|SymfonyResponse
    {
        if (! self::on()) {
            return self::switchedOff();
        }

        $estate = (string) tenant()->getTenantKey();
        $viewer = self::viewer($request);
        $page = $log->estatePage($estate, $viewer, self::filters($request), TableQuery::from($request, AuditLogger::tableSorts(), AuditLogger::DEFAULT_SORT, AuditLogger::DEFAULT_DIRECTION, AuditLogger::PER_PAGE));

        return inertia('Estate/Settings/Audit', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('audit', $estate),
            'rows' => $page['rows'],
            'table' => $page['table'],
            'filters' => self::filters($request),
            'people' => $log->estateActors($estate, $viewer),
            'modules' => $log->estateModules($estate, $viewer),
        ]);
    }

    public function export(Request $request, AuditLogger $log): StreamedResponse|SymfonyResponse
    {
        if (! self::on()) {
            return self::switchedOff();
        }

        $estate = (string) tenant()->getTenantKey();
        $rows = $log->estateExport($estate, self::viewer($request), self::filters($request));
        $name = 'audit-log-'.$estate.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(static function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['When', 'Who', 'Role', 'What they did', 'Done to', 'Before', 'After'], ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['when'], $row['who'], $row['role'], $row['what'], $row['target'],
                    implode('; ', (array) $row['before']), implode('; ', (array) $row['after']),
                ], ',', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** `/audit`, which used to land on the dashboard: the page itself while it is on, and while it is off, the page saying so. */
    public function redirect(): RedirectResponse|SymfonyResponse
    {
        if (! self::on()) {
            return self::switchedOff();
        }

        // In whichever shape this environment serves an estate (routes/tenant.php): its own host, or the path form.
        return redirect()->to(app()->isLocal() ? '/estate/'.tenant()->getTenantKey().'/settings/audit' : '/settings/audit');
    }

    public static function on(): bool
    {
        return (bool) config('estate_features.audit_log', false);
    }

    /** The page a switched-off module answers with, as a module the plan leaves out does (`EnsureEstatePlanIncludes`): a 403. */
    private static function switchedOff(): SymfonyResponse
    {
        return Inertia::render('Estate/NotInPlan', [
            'estate' => ['name' => (string) tenant()?->name],
            'module' => 'The audit log',
            'switchedOff' => true,
        ])->toResponse(request())->setStatusCode(403);
    }

    /** Whose permissions decide which entries are shown: the person signed in, whom the route's gate already checked. */
    private static function viewer(Request $request): User
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 403);

        return $viewer;
    }

    /** @return array{actor: string|null, module: string|null, from: string|null, to: string|null} */
    private static function filters(Request $request): array
    {
        $text = static fn (string $key): ?string => is_string($request->query($key)) && trim((string) $request->query($key)) !== '' ? trim((string) $request->query($key)) : null;

        return ['actor' => $text('actor'), 'module' => $text('module'), 'from' => $text('from'), 'to' => $text('to')];
    }
}
