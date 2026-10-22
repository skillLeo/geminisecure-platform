<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DuressAlert;
use App\Models\Estate\Ballot;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\PayrollRun as EstatePayrollRun;
use App\Models\Estate\Unit;
use App\Models\Estate\Vendor;
use App\Models\Guard;
use App\Models\Invoice;
use App\Models\PayrollRun;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;

/**
 * Pixel-diffs each built screen against the wireframe it came from.
 *
 * This replaces describing what was built. A screen is not done because it
 * looks right in a screenshot; it is done when the diff against the board is
 * under the threshold, measured, every time.
 *
 * The command owns the screen-to-route mapping because only PHP can resolve
 * the record ids a detail route needs. It writes those resolved URLs to
 * _design/SCREEN_TARGETS.json and hands the actual comparison to Playwright.
 */
class FidelityCheck extends Command
{
    protected $signature = 'fidelity:check
        {screen? : One screen id, e.g. super-admin-04. Omit to check every mapped screen.}
        {--threshold=2.0 : Maximum percentage of differing pixels before a screen fails.}
        {--url=http://127.0.0.1:8000 : Where the application is served.}';

    protected $description = 'Pixel-diff built screens against their approved wireframes';

    /**
     * Screen id => the route that reproduces it.
     *
     * 'params' names a resolver below rather than a literal id, because a
     * hardcoded id becomes wrong the first time anyone reseeds.
     *
     * 'depicts' pins a detail screen to the RECORD ITS BOARD DRAWS. The boards
     * say which one in their own titles — "Client Detail — Phoenix Park
     * Village 1" is a different screen from "Client Detail — Ocean View
     * Gardens", and they are numbered separately. Opening whichever record
     * happens to sort first diffs one estate's data against another's and
     * reports it as a styling fault.
     *
     * 'estate' and 'role' mark an ESTATE board. The first names the tenant its
     * URL is built for, the second the quick-login the harness signs in as —
     * the Gemini Director cannot reach an estate console at all, so measuring
     * one as him would diff a 403 against its board and report a plausible
     * percentage.
     *
     * @var array<string, array{route: string, params?: string, depicts?: string, identifier?: string, guest?: bool, estate?: string, role?: string, content?: string}>
     */
    private const MAPPING = [
        // --- auth ------------------------------------------------------
        'super-admin-01' => ['route' => 'login', 'guest' => true],

        // --- dashboard -------------------------------------------------
        'super-admin-02' => ['route' => 'gemini.dashboard'],
        'super-admin-03' => ['route' => 'gemini.dashboard.activity'],

        // --- clients ---------------------------------------------------
        'super-admin-04' => ['route' => 'gemini.clients'],
        'super-admin-05' => ['route' => 'gemini.clients.show', 'params' => 'tenant', 'depicts' => 'phoenixpark'],
        // "Ocean View Gardens — activate plan". The board names an ONBOARDING
        // client, not a live one: activating a plan is what happens at the end
        // of onboarding, so the screen is drawn on the estate that is having it
        // done to them.
        'super-admin-06' => ['route' => 'gemini.clients.plan', 'params' => 'tenant', 'depicts' => 'oceanview'],
        'super-admin-08' => ['route' => 'gemini.clients.create'],
        'super-admin-09' => ['route' => 'gemini.clients.show', 'params' => 'tenant', 'depicts' => 'oceanview'],
        'super-admin-10' => ['route' => 'gemini.clients.guards', 'params' => 'tenant', 'depicts' => 'phoenixpark'],
        'super-admin-11' => ['route' => 'gemini.clients.message', 'params' => 'tenant', 'depicts' => 'phoenixpark'],

        // --- dispatch --------------------------------------------------
        'super-admin-12' => ['route' => 'gemini.dispatch.map'],
        'super-admin-13' => ['route' => 'gemini.dispatch.coverage'],
        'super-admin-14' => ['route' => 'gemini.dispatch'],
        'super-admin-15' => ['route' => 'gemini.dispatch.alertness'],
        'super-admin-16' => ['route' => 'gemini.dispatch.requests'],
        'super-admin-17' => ['route' => 'gemini.dispatch.alert', 'params' => 'alert'],

        // --- guard workforce -------------------------------------------
        'super-admin-18' => ['route' => 'gemini.guard_workforce'],
        'super-admin-19' => ['route' => 'gemini.guard_workforce.show', 'params' => 'guard', 'depicts' => 'GS-1041'],
        'super-admin-20' => ['route' => 'gemini.guard_workforce.compliance'],
        'super-admin-21' => ['route' => 'gemini.guard_workforce.compliance_action', 'params' => 'guard', 'depicts' => 'GS-1049'],
        'super-admin-22' => ['route' => 'gemini.guard_workforce.create'],
        'super-admin-23' => ['route' => 'gemini.guard_workforce.show', 'params' => 'guard', 'depicts' => 'GS-1049'],

        // --- security operations ---------------------------------------
        'super-admin-24' => ['route' => 'gemini.guard_workforce.roster'],
        'super-admin-25' => ['route' => 'gemini.guard_workforce.standing_orders'],
        'super-admin-26' => ['route' => 'gemini.guard_workforce.gate_activity'],
        'super-admin-27' => ['route' => 'gemini.guard_workforce.incidents'],

        // --- payroll ---------------------------------------------------
        'super-admin-28' => ['route' => 'gemini.payroll_accounting'],
        'super-admin-29' => ['route' => 'gemini.payroll_accounting.show', 'params' => 'run', 'depicts' => 'GS-PR-202609-2'],
        'super-admin-30' => ['route' => 'gemini.payroll_accounting.filings'],
        'super-admin-31' => ['route' => 'gemini.payroll_accounting.rates'],

        // --- billing ---------------------------------------------------
        'super-admin-32' => ['route' => 'gemini.billing_subscriptions'],
        // Board title: "Invoice — Phoenix Park, Aug 2026".
        'super-admin-33' => ['route' => 'gemini.billing_subscriptions.invoice', 'params' => 'invoice', 'depicts' => 'PH-INV-202608'],
        'super-admin-34' => ['route' => 'gemini.billing_subscriptions.plans'],
        'super-admin-35' => ['route' => 'gemini.billing_subscriptions.payment_methods'],

        // --- cross-tenant reports --------------------------------------
        'super-admin-07' => ['route' => 'gemini.cross_tenant_reports.client_health'],
        'super-admin-36' => ['route' => 'gemini.cross_tenant_reports'],
        'super-admin-37' => ['route' => 'gemini.cross_tenant_reports.mrr'],
        'super-admin-38' => ['route' => 'gemini.cross_tenant_reports.revenue'],
        'super-admin-39' => ['route' => 'gemini.cross_tenant_reports.churn'],
        'super-admin-40' => ['route' => 'gemini.cross_tenant_reports.utilisation'],

        // --- audit -----------------------------------------------------
        'super-admin-41' => ['route' => 'gemini.access_audit_log'],

        // --- platform settings -----------------------------------------
        /*
         * Measured again as of the settings rename. This row was UNVERIFIED
         * while `gemini.platform_settings` served /settings/roles, because it
         * was then comparing board 42 against screen 45 — two boards sharing a
         * shell, producing a believable percentage that measured nothing.
         * `gemini.platform_settings` is now the module's own landing screen.
         */
        'super-admin-42' => ['route' => 'gemini.platform_settings'],
        'super-admin-43' => ['route' => 'gemini.platform_settings.packages'],
        'super-admin-44' => ['route' => 'gemini.platform_settings.line_items'],
        'super-admin-45' => ['route' => 'gemini.platform_settings.roles'],

        /* ============================================================== */
        /* ESTATE CONSOLE */
        /* ============================================================== */

        /*
         * Every estate board is drawn INSIDE one estate and as a committee
         * member, so each carries both. `estate` names the tenant the URL is
         * built for; `role` names the quick-login the harness signs in as. The
         * Director is deliberately not usable here — he cannot reach an estate
         * console at all, and measuring these as him would diff a 403 against
         * each board.
         *
         * Phoenix Park is the estate every board is drawn from: 450 units,
         * J$1,840,000 of arrears, Lot 47 owing J$12,400. Ocean View is
         * mid-onboarding and has no dues history by design.
         *
         * `depicts` is a unit REFERENCE rather than an id, for the same reason
         * the Gemini detail boards use employee numbers: an id changes the first
         * time anyone reseeds and the reference is what the board prints.
         */
        'community-admin-05' => [
            'route' => 'estate.dues.arrears',
            'estate' => 'phoenixpark',
            'role' => 'estate.treasurer',
            'content' => '.main-col',
        ],
        'community-admin-06' => [
            'route' => 'estate.dues.unit',
            'estate' => 'phoenixpark',
            'params' => 'unit',
            'depicts' => 'Lot 47',
            'role' => 'estate.treasurer',
            'content' => '.main-col',
        ],
        /*
         * The dunning log. No `params`: board 8 is the estate's own log rather
         * than one unit's, so it takes no record and the URL is the module
         * route with the estate on it.
         */
        'community-admin-08' => [
            'route' => 'estate.dues.dunning',
            'estate' => 'phoenixpark',
            'role' => 'estate.treasurer',
            'content' => '.main-col',
        ],
        /*
         * "Place Lot 47 on a payment plan". A modal in the board and a route in
         * the application, measured — like its two neighbours — at the unit the
         * board draws in its own heading and figures.
         */
        'community-admin-07' => [
            'route' => 'estate.dues.plan',
            'estate' => 'phoenixpark',
            'params' => 'unit',
            'depicts' => 'Lot 47',
            'role' => 'estate.treasurer',
            'content' => '.main-col',
        ],
        'community-admin-25' => [
            'route' => 'estate.accounting.chart',
            'estate' => 'phoenixpark',
            'role' => 'estate.treasurer',
            'content' => '.main-col',
        ],
        'community-admin-26' => [
            'route' => 'estate.accounting.vendors',
            'estate' => 'phoenixpark',
            'role' => 'estate.treasurer',
            'content' => '.main-col',
        ],
        // "Vendor Detail — Island Electric". The board names the supplier in its
        // own title, and the name is what the register prints, so it is the key
        // — the id changes the first time anyone reseeds.
        'community-admin-39' => [
            'route' => 'estate.accounting.vendor',
            'estate' => 'phoenixpark',
            'params' => 'vendor',
            'depicts' => 'Island Electric Services',
            'role' => 'estate.treasurer',
            'content' => '.main-col',
        ],
        'community-admin-27' => [
            'route' => 'estate.accounting.bills',
            'estate' => 'phoenixpark',
            'role' => 'estate.treasurer',
            'content' => '.main-col',
        ],
        'community-admin-28' => [
            'route' => 'estate.accounting.reconciliation',
            'estate' => 'phoenixpark',
            'role' => 'estate.treasurer',
            'content' => '.main-col',
        ],
        'community-admin-35' => [
            'route' => 'estate.dues.charge.new',
            'estate' => 'phoenixpark',
            'role' => 'estate.treasurer',
            'content' => '.main-col',
        ],

        // Board 32's own sidebar footer names Delroy Samuels, Secretary — the
        // officer who posts an AGM notice, and a role holding Governance create.
        'community-admin-32' => [
            'route' => 'estate.governance.notices',
            'estate' => 'phoenixpark',
            'role' => 'estate.secretary',
            'content' => '.main-col',
        ],
        /*
         * Board 29's own sidebar footer names Patrice Campbell, President — a
         * role holding Full on Reports, so every card's control is drawn for
         * the reason the CATALOGUE gives rather than for want of access. Measured
         * as the Secretary or the Property Manager, both of whom hold View, the
         * seven titles would all read "this role can read the catalogue" and the
         * screen would look identical while saying something else entirely.
         */
        'community-admin-29' => [
            'route' => 'estate.reports',
            'estate' => 'phoenixpark',
            'role' => 'estate.president',
            'content' => '.main-col',
        ],
        'community-admin-30' => [
            'route' => 'estate.settings.notifications',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        'community-admin-33' => [
            'route' => 'estate.settings.privacy',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        'community-admin-40' => [
            'route' => 'estate.settings.billing',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],

        /*
         * Payroll, measured as the COMMUNITY SUPER ADMIN.
         *
         * The matrix gives Payroll Full · Approver to that role alone (D-063)
         * and Full to the Treasurer, so it is the only one for whom board 15
         * draws its approval control as anything but absent. The Property
         * Manager — who is board 15's own first payslip and board 37's first
         * row — is locked out of the module entirely by D-010, and measuring as
         * them would diff a 403 against a board.
         */
        'community-admin-13' => [
            'route' => 'estate.payroll.runs',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        // Board 14's own URL is /payroll/runs/sep-2026/exceptions — the run
        // still stuck on the two things board 14 exists to draw.
        'community-admin-14' => [
            'route' => 'estate.payroll.run.exceptions',
            'estate' => 'phoenixpark',
            'params' => 'slug',
            'depicts' => 'sep-2026',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        // And board 15's is /payroll/runs/aug-2026 — the run that is calculated
        // and waiting for a second approver.
        'community-admin-15' => [
            'route' => 'estate.payroll.run',
            'estate' => 'phoenixpark',
            'params' => 'slug',
            'depicts' => 'aug-2026',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        'community-admin-16' => [
            'route' => 'estate.payroll.filings',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        'community-admin-37' => [
            'route' => 'estate.payroll.employees',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],

        /*
         * The estate's own sign-in door. `guest` AND `estate` together: the
         * harness signs in as nobody, but still builds the URL inside Phoenix
         * Park, because the card names the community in its own footer and the
         * central door names Gemini instead.
         */
        'community-admin-01' => [
            'route' => 'estate.login',
            'estate' => 'phoenixpark',
            'guest' => true,
        ],

        /*
         * The estate's front door, measured as the President — the persona its
         * own sidebar footer names, and a role that holds the ledger, so the
         * arrears panel draws rather than saying it may not be read.
         */
        'community-admin-02' => [
            'route' => 'estate.home',
            'estate' => 'phoenixpark',
            'role' => 'estate.president',
            'content' => '.main-col',
        ],

        /*
         * The estate and its households, measured as the COMMUNITY SUPER ADMIN.
         *
         * The matrix gives that role Full on `estate_structure` and Approver on
         * `residents`, which is every control these five boards draw. The
         * Property Manager holds the same two cells and is the persona board 31
         * puts in its own sidebar footer — but D-010 locks that role out of the
         * ledger, and Q-014's safe option therefore withholds board 31's "90+
         * days arrears" flag from them. Measuring as the Property Manager would
         * diff a screen with that flag suppressed against a board that draws it,
         * and report a standing invariant as a styling fault.
         */
        'community-admin-03' => [
            'route' => 'estate.structure',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        'community-admin-04' => [
            'route' => 'estate.residents.index',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        'community-admin-31' => [
            'route' => 'estate.residents.claims',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        'community-admin-34' => [
            'route' => 'estate.residents.new',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        // "Resident Detail — Andrea Fletcher", who is Lot 47 on every board that
        // names her: the unit ledger, the payment plan and this one.
        'community-admin-38' => [
            'route' => 'estate.residents.show',
            'estate' => 'phoenixpark',
            'params' => 'unit',
            'depicts' => 'Lot 47',
            'identifier' => 'slug',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],

        /*
         * Governance, measured as the officer who can actually work the screen.
         *
         * The Secretary RUNS an election — opens nominations, closes the poll,
         * vets a candidate — and the President CERTIFIES it. Board 11 draws the
         * certify control live, so it is measured as the President; the other
         * four are the Secretary's. Signing in as the wrong officer would diff a
         * screen with its primary action greyed out against a board that draws
         * it enabled, and report the difference as a styling fault.
         */
        'community-admin-09' => [
            'route' => 'estate.governance.election',
            'estate' => 'phoenixpark',
            'params' => 'year',
            'depicts' => '2026',
            'role' => 'estate.secretary',
            'content' => '.main-col',
        ],
        'community-admin-10' => [
            'route' => 'estate.governance.nominations',
            'estate' => 'phoenixpark',
            'params' => 'year',
            'depicts' => '2026',
            'role' => 'estate.secretary',
            'content' => '.main-col',
        ],
        'community-admin-11' => [
            'route' => 'estate.governance.results',
            'estate' => 'phoenixpark',
            'params' => 'year',
            'depicts' => '2026',
            'role' => 'estate.president',
            'content' => '.main-col',
        ],
        /*
         * 12 IS THE SCHEDULER AND 36 IS THE LIST, which is the opposite of what
         * the numbering suggests. Board 12's own URL is
         * /governance/meetings/new and board 36's is /governance/meetings —
         * taken from the boards rather than from the order they were drawn in.
         */
        'community-admin-12' => [
            'route' => 'estate.governance.meeting.new',
            'estate' => 'phoenixpark',
            'role' => 'estate.secretary',
            'content' => '.main-col',
        ],
        'community-admin-36' => [
            'route' => 'estate.governance.meetings',
            'estate' => 'phoenixpark',
            'role' => 'estate.secretary',
            'content' => '.main-col',
        ],

        /*
         * Facilities, measured as the Property Manager — the role the estate
         * matrix gives Full on Maintenance and Amenities, and the one boards 17
         * to 20 are drawn for.
         */
        'community-admin-17' => [
            'route' => 'estate.facilities.maintenance',
            'estate' => 'phoenixpark',
            'role' => 'estate.property_manager',
            'content' => '.main-col',
        ],
        // "Ticket #1042 — Gate lighting". Bound on the number the board prints.
        'community-admin-18' => [
            'route' => 'estate.facilities.ticket',
            'estate' => 'phoenixpark',
            'params' => 'ticket',
            'depicts' => '1042',
            'role' => 'estate.property_manager',
            'content' => '.main-col',
        ],
        'community-admin-19' => [
            'route' => 'estate.facilities.bookings',
            'estate' => 'phoenixpark',
            'role' => 'estate.property_manager',
            'content' => '.main-col',
        ],
        'community-admin-20' => [
            'route' => 'estate.facilities.amenities',
            'estate' => 'phoenixpark',
            'role' => 'estate.property_manager',
            'content' => '.main-col',
        ],

        /*
         * Settings, measured as the COMMUNITY SUPER ADMIN — the estate's own
         * administrator and the only estate role that can work these screens.
         *
         * The Settings row of the matrix gives Full to that role, View to the
         * President and the Vice President, and nothing to the other four. So
         * the Secretary, Property Manager, Treasurer and Admin Assistant get 403
         * on all four routes and would diff an error page against a board, and
         * the President — who board 21 names in its own sidebar footer — opens
         * every screen and can save none of them: board 21's "Save changes" and
         * board 23's seven switches would all measure greyed against a board
         * that draws them live.
         *
         * Board 24 is the exception that proves the rule and is measured as the
         * same role anyway: `can_edit` is false for all seven, so no role makes
         * that screen more live than another.
         */
        'community-admin-21' => [
            'route' => 'estate.settings.profile',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        'community-admin-22' => [
            'route' => 'estate.settings.users',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        'community-admin-23' => [
            'route' => 'estate.settings.features',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
        'community-admin-24' => [
            'route' => 'estate.settings.roles',
            'estate' => 'phoenixpark',
            'role' => 'estate.community_super_admin',
            'content' => '.main-col',
        ],
    ];

    public function handle(): int
    {
        $regionsPath = base_path('_design/SCREEN_REGIONS.json');

        if (! File::exists($regionsPath)) {
            $this->error('_design/SCREEN_REGIONS.json is missing. Run: node tests/Fidelity/build-regions.mjs');

            return self::FAILURE;
        }

        /** @var array{screens: list<array<string, mixed>>} $regions */
        $regions = json_decode(File::get($regionsPath), true, 512, JSON_THROW_ON_ERROR);

        /*
         * Generate URLs on the same host the harness browses.
         *
         * Without this, route() builds from APP_URL (localhost) while the
         * browser signs in against --url (127.0.0.1). Those are different
         * origins to a cookie jar, so every authenticated screen quietly
         * redirects to /login and diffs the sign-in page against its board.
         */
        URL::forceRootUrl($this->option('url'));

        $only = $this->argument('screen');
        $targets = [];
        $skipped = [];

        foreach ($regions['screens'] as $screen) {
            $id = $screen['id'];

            if ($only !== null && $id !== $only) {
                continue;
            }

            if (! isset(self::MAPPING[$id])) {
                $skipped[] = $id;

                continue;
            }

            $url = $this->urlFor(self::MAPPING[$id]);

            if ($url === null) {
                $this->warn("{$id}: no seed record for this route, skipping.");

                continue;
            }

            $targets[] = [
                'id' => $id,
                'title' => $screen['title'],
                'source' => $screen['source'],
                'selector' => $screen['selector'],
                'index' => $screen['index'],
                'viewport' => $screen['viewport'],
                'url' => $url,
                'guest' => self::MAPPING[$id]['guest'] ?? false,

                /*
                 * WHAT THE BOARD IS ACTUALLY AUTHORITATIVE ABOUT.
                 *
                 * Estate boards draw a sidebar that no role can see: board 05's
                 * persona is the Property Manager and its sidebar carries the
                 * three money modules Ruling 1 locks that role out of. The
                 * matrix generates navigation, so the console's sidebar is
                 * right and the picture is wrong — but measured whole, every
                 * estate screen then carries a two-point delta from one cause
                 * nobody may fix, and a target nobody can reach is a target
                 * a developer burns iterations against.
                 *
                 * So the diff is clipped to `.main-col` on BOTH sides. The
                 * board keeps its authority over the part it is authoritative
                 * about — the content — and the sidebar is asserted where it
                 * belongs, against the permission matrix, in
                 * EstateNavigationTest. A test, not a picture.
                 */
                'contentSelector' => self::MAPPING[$id]['content'] ?? null,

                /*
                 * Which role the board is drawn as. The Gemini boards are all
                 * the Director; the estate boards are a committee member, and
                 * the Director cannot reach an estate console at all — measured
                 * as him, every estate screen would diff a 403 against its
                 * board and report a plausible percentage.
                 */
                'role' => self::MAPPING[$id]['role'] ?? 'gemini.director',
                'unverified' => self::unverifiedReason($id),
            ];
        }

        if ($targets === []) {
            $this->error($only !== null
                ? "Screen '{$only}' is not mapped to a route yet."
                : 'No screens are mapped to routes yet.');

            return self::FAILURE;
        }

        File::put(
            base_path('_design/SCREEN_TARGETS.json'),
            json_encode([
                'note' => 'Generated by php artisan fidelity:check. Resolved URLs for the pixel harness.',
                'app_url' => $this->option('url'),
                'threshold_percent' => (float) $this->option('threshold'),
                'targets' => $targets,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
        );

        $this->line(sprintf(
            '%d screen(s) mapped, %d of 165 still unmapped.',
            count($targets),
            count($regions['screens']) - count(self::MAPPING)
        ));
        $this->newLine();

        $process = new Process(['node', 'tests/Fidelity/compare.mjs'], base_path(), null, null, 900.0);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{route: string, params?: string, depicts?: string, guest?: bool, estate?: string, role?: string}  $mapping
     */
    private function urlFor(array $mapping): ?string
    {
        if (! Route::has($mapping['route'])) {
            return null;
        }

        /*
         * An ESTATE board is measured inside one estate's own console, so its
         * URL carries the estate and its record has to be resolved from that
         * estate's database rather than the central one. `tenancy()->initialize`
         * is what makes `Unit::find()` mean anything at all here — outside it,
         * the query would run against gs_platform, where no unit exists.
         */
        if (isset($mapping['estate'])) {
            return $this->estateUrl($mapping);
        }

        if (! isset($mapping['params'])) {
            return route($mapping['route']);
        }

        /*
         * A detail board depicts one record, named in its own title. Resolve
         * that record by a stable business key — a subdomain, an employee
         * number — rather than an auto-increment id, which changes the first
         * time anyone reseeds.
         */
        $id = isset($mapping['depicts'])
            ? $this->depicted($mapping['params'], $mapping['depicts'])
            : match ($mapping['params']) {
                'tenant' => Tenant::estates()->value('id'),
                'guard' => Guard::query()->value('id'),
                'alert' => DuressAlert::query()->value('id'),
                'run' => PayrollRun::query()->value('id'),
                'invoice' => Invoice::query()->value('id'),
                default => null,
            };

        return $id === null ? null : route($mapping['route'], [$mapping['params'] => $id]);
    }

    /**
     * A unit reference in the form its own URL carries: "Lot 47" → "lot-47".
     *
     * The transform is `ResidentsController::resolveUnit`'s, read backwards. It
     * is duplicated here rather than shared because the two live on opposite
     * sides of the thing being tested — a harness that imported the controller's
     * own helper would agree with it by construction, including when both are
     * wrong.
     */
    private function unitSlug(?string $reference): ?string
    {
        return $reference === null ? null : str_replace(' ', '-', strtolower($reference));
    }

    /**
     * The URL of a board that lives inside one estate.
     *
     * `depicts` is a BUSINESS KEY — "Lot 47", "Island Electric Services" —
     * because that is what the board prints in its own title, and it survives a
     * reseed where an id does not.
     *
     * @param  array{route: string, params?: string, depicts?: string, identifier?: string, estate?: string}  $mapping
     */
    private function estateUrl(array $mapping): ?string
    {
        $estate = Tenant::find($mapping['estate']);

        if ($estate === null) {
            return null;
        }

        $parameters = ['tenant' => $estate->getTenantKey()];

        if (isset($mapping['params'])) {
            $key = $mapping['depicts'] ?? '';

            /*
             * Inside the estate's own database, and keyed on what the board
             * prints. A unit is its reference — "Lot 47" — and a vendor is its
             * name, because that is what board 39 puts in its title and what the
             * register on board 26 identifies a supplier by. Neither is an id:
             * an id changes the first time anyone reseeds.
             *
             * A TICKET AND AN ELECTION ARE NOT IDS AT ALL, and their routes say
             * so. `maintenance/{ticket}` binds on the ticket NUMBER — what board
             * 18 prints, and what a bill already stores against the job — and
             * `elections/{year}` binds on the year. Both are already the
             * business key, so each is LOOKED UP RATHER THAN TRUSTED: pointing
             * the harness at a ticket or an election the estate does not hold
             * would measure a 404 against its board and report a percentage.
             */
            /*
             * WHICH COLUMN THE URL CARRIES IS THE ROUTE'S DECISION, NOT THIS
             * COMMAND'S, and two routes bound to the same record disagree about
             * it. `finance/units/{unit}` takes the id; `residents/{unit}` takes
             * the slug, because a resident's page is a thing somebody links to
             * and "lot-47" survives a reseed where an id does not. Resolving
             * both the same way gave board 38 a URL the router answered 404 to
             * and a diff that read 100%. `identifier` says which form to build,
             * and either way the record is LOOKED UP first — a reference typed
             * here that no unit carries has to fail loudly rather than produce a
             * plausible-looking miss.
             */
            $identifier = $mapping['identifier'] ?? 'id';

            $id = $estate->run(fn (): int|string|null => match ($mapping['params']) {
                'unit' => $identifier === 'slug'
                    ? $this->unitSlug(Unit::query()->where('reference', $key)->value('reference'))
                    : Unit::query()->where('reference', $key)->value('id'),
                'vendor' => Vendor::query()->where('name', $key)->value('id'),
                'ticket' => MaintenanceTicket::query()->where('number', (int) $key)->value('number'),
                'year' => Ballot::query()->where('year', (int) $key)->value('year'),
                'slug' => EstatePayrollRun::query()->where('slug', $key)->value('slug'),
                default => null,
            });

            if ($id === null) {
                return null;
            }

            $parameters[$mapping['params']] = $id;
        }

        /*
         * The PATH form, explicitly — not whatever `route()` hands back.
         *
         * Every estate route is registered twice under the same name: once on
         * `{tenant}.geminisecure.com` for production and once as
         * `/estate/{tenant}/...` for local, because *.localhost does not
         * resolve on Windows. `route()` returns the first match, which is the
         * production hostname, and the harness then fails to resolve a DNS name
         * that only exists in production. Picking the registration with no
         * domain constraint is what makes this measurable locally.
         */
        $local = null;

        // Every route, not `getRoutesByName()` — that map holds one entry per
        // name and the estate routes are registered twice under each.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === $mapping['route'] && $route->getDomain() === null) {
                $local = $route;

                break;
            }
        }

        if ($local === null) {
            return route($mapping['route'], $parameters);
        }

        return url($this->substitute($local->uri(), $parameters));
    }

    /**
     * Fill a route URI's placeholders by hand.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function substitute(string $uri, array $parameters): string
    {
        foreach ($parameters as $key => $value) {
            $uri = str_replace(['{'.$key.'}', '{'.$key.'?}'], (string) $value, $uri);
        }

        return $uri;
    }

    /**
     * Why a screen's number should not be read as a verdict.
     *
     * A board built out of order can end up measured against a sibling that
     * shares its shell — the route it will eventually own does not exist yet,
     * so the name resolves to a neighbour. That produces a plausible
     * percentage which measures nothing, and a plausible percentage is worse
     * than none: it reads as a pass. Listed here, such a row prints
     * "UNVERIFIED (reason)" with no figure, writes no diff images, and counts
     * in neither the numerator nor the denominator.
     *
     * super-admin-42 sat here until the platform settings rename gave it its
     * own route.
     *
     * THE THREE PAYROLL BOARDS DRAWN BEFORE THE Q-002 RULING (12 §4). They draw
     * PAYE as a cliff on the whole salary and the Net figures that follow; the
     * engine follows the ruling and is not bent to match them. Each wrong cell,
     * what it draws, what the engine produces and why is in
     * docs/reports/BOARD_CORRECTIONS.md, which goes to the designer. Until they
     * are redrawn, a percentage against them would measure a known-wrong board.
     */
    private static function unverifiedReason(string $screenId): ?string
    {
        $redraw = 'board draws the overruled PAYE cliff (D-082/D-083) — awaiting redraw, see docs/reports/BOARD_CORRECTIONS.md';

        /** @var array<string, string> $unverified */
        $unverified = [
            'community-admin-13' => $redraw,
            'community-admin-15' => $redraw,
            'community-admin-16' => $redraw,
        ];

        return $unverified[$screenId] ?? null;
    }

    /**
     * The record a board draws, found by its business key.
     *
     * A tenant IS its subdomain, so that key is the id. A guard is found by
     * employee number and an invoice by reference, because both of those are
     * printed on the board itself and neither moves when the database is
     * rebuilt.
     */
    private function depicted(string $param, string $key): int|string|null
    {
        return match ($param) {
            'tenant' => Tenant::find($key)?->getTenantKey(),
            'guard' => Guard::query()->where('employee_number', $key)->value('id'),
            'invoice' => Invoice::query()->where('reference', $key)->value('id'),
            // `period` is an accessor, not a column — the stored key is the run
            // reference. Querying the accessor silently found nothing and fell
            // through to "no seed record", which reads as missing data rather
            // than a wrong lookup.
            'run' => PayrollRun::query()->where('reference', $key)->value('id'),
            default => null,
        };
    }
}
