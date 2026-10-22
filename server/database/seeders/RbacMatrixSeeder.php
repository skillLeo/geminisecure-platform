<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Enums\Console;
use App\Enums\PermissionVerb;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Support\NamedActs;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the role access matrix, parsed from the approved wireframes.
 *
 *   Gemini  — Super Admin "09 Platform Settings.html", screen 45, lines 1066-1152
 *   Estate  — Community Admin "06 Settings ... and Roles.html", screen 24, lines 581-685
 *
 * Cell notation matches the wireframe pills exactly:
 *   F   Full                 A   Full · Approver
 *   S   Scoped               V   View
 *   E   Entry                -   no access
 *
 * AND ONE SUFFIX NO WIREFRAME DRAWS (M11 B3): `+act,act` after a pill grants
 * those named acts one by one (`App\Support\NamedActs`) — `F+set_rates` is Full
 * plus the one dues approval that sets rates, and none of the other four. A
 * pill's acts must be real acts of that module and must not already be implied
 * by the pill (`A` is every approval), or the seeder refuses the grid.
 *
 * Idempotent: re-running updates cells in place rather than duplicating them.
 *
 * A RE-SEED KEEPS WHAT THE DIRECTOR CHANGED (M10 §4). Every deploy that changes
 * the matrix runs this seeder, and the Director can now change what a seeded
 * Gemini role may do. So a seeded Gemini role marked as edited
 * (`roles.permissions_edited_at`, set by `RoleEditor::setPermissions()`) keeps
 * every cell it already has, along with the permissions compiled from those
 * cells. It still gets a cell, at the grid's value, for any module it has no
 * cell for, so a module the grid introduces reaches it. It still takes its
 * label, sort and scope from here. A seeded role nobody changed follows the
 * grid exactly as it always has.
 *
 * THE EIGHT ESTATE ROLES ALWAYS FOLLOW THE GRID. They are shared by every
 * estate, and nothing lets an estate change one, so no estate role is ever
 * marked as edited. This seeder clears the mark on them anyway, so a hand edit
 * to the database cannot opt one out of the platform's definition.
 *
 * THE WAY BACK is `RoleEditor::resetToDefault()` (the console's "Reset to
 * GeminiSecure default") or `php artisan rbac:reset-role`. Both read the
 * defaults from `defaultsFor()`, so a reset and a first seed cannot disagree
 * about what a role's default is.
 *
 * The grid is read through protected accessors (`geminiGrid()` and the rest)
 * rather than straight from the constants. That lets a test run a subclass
 * that adds a module or changes a cell, and so prove what a real grid change
 * does to an edited role and to an unedited one.
 */
class RbacMatrixSeeder extends Seeder
{
    /** Wireframe pill -> [level, can_approve, scope] */
    private const CELLS = [
        'F' => [AccessLevel::Full, false, AccessScope::All],
        'A' => [AccessLevel::Full, true, AccessScope::All],
        'S' => [AccessLevel::Full, false, AccessScope::AssignedSites],
        'V' => [AccessLevel::View, false, AccessScope::All],
        'E' => [AccessLevel::Entry, false, AccessScope::All],
        '-' => [AccessLevel::None, false, AccessScope::All],
    ];

    /**
     * [key, label, section, sort, is_locked_financial]
     *
     * Order and grouping are the sidebar's, verbatim from the approved
     * wireframe: Dashboard sits above both headings, then Platform, then
     * System.
     */
    private const GEMINI_MODULES = [
        ['dashboard', 'Dashboard', null, 10, false],
        ['clients', 'Clients', 'Platform', 20, false],
        ['dispatch', 'Dispatch', 'Platform', 25, false],
        ['guard_workforce', 'Guard workforce', 'Platform', 30, false],
        ['payroll_accounting', 'Payroll & Accounting', 'Platform', 40, false],
        ['billing_subscriptions', 'Billing & subscriptions', 'Platform', 50, false],
        ['cross_tenant_reports', 'Cross-tenant reports', 'Platform', 60, false],
        ['access_audit_log', 'Access & audit log', 'System', 70, false],
        ['platform_settings', 'Platform settings', 'System', 80, false],
    ];

    /**
     * Estate modules, after the Ruling 1 split (D-010).
     *
     * `accounting` became accounting_posting + vendor_costs, and
     * maintenance_budget was split away from dues_ledger, so that the
     * Property Manager can see costs on work they commissioned without ever
     * seeing a resident's financial position.
     */
    private const ESTATE_MODULES = [
        ['dashboard', 'Dashboard', null, 10, false],
        ['estate_structure', 'Estate structure', 'Community', 20, false],
        ['residents', 'Residents', 'Community', 30, false],
        ['dues_ledger', 'Dues & ledger', 'Money', 40, true],
        ['payments', 'Payments', 'Money', 50, true],
        ['accounting_posting', 'Accounting', 'Money', 60, true],
        ['vendor_costs', 'Vendor costs', 'Money', 70, false],
        ['maintenance_budget', 'Maintenance budget', 'Money', 80, false],
        ['payroll', 'Payroll & HR', 'Money', 90, true],
        ['facilities', 'Facilities', 'Community', 100, false],
        ['governance', 'Governance', 'Community', 110, false],

        /*
         * SECURITY — a new estate module (M10 §2).
         *
         * The estate console has never had one. Panic alerts, incidents, gate
         * activity and the visitor log all live in the central database and were
         * readable only from the Gemini console, so the community whose gate it
         * is could not see its own security picture at all.
         *
         * A MODULE AND NOT A PERMISSION ON AN EXISTING ONE. Its nearest
         * neighbours are Facilities, which is maintenance and amenities, and
         * Governance, which is meetings and elections. Folding security into
         * either would mean granting the new Head of Security a module that also
         * carries work orders or ballots, and would mean every existing role
         * that holds Facilities silently gaining sight of panic alerts.
         */
        ['security', 'Security', 'Community', 115, false],

        ['reports', 'Reports', 'System', 120, false],
        ['settings', 'Settings', 'System', 130, false],
    ];

    /** [name, label, sort, scope_default] */
    private const GEMINI_ROLES = [
        [Role::DIRECTOR, 'Director', 10, AccessScope::All],
        [Role::OPERATIONS_MANAGER, 'Operations Manager', 20, AccessScope::All],
        [Role::HEAD_OF_SECURITY, 'Head of Security', 30, AccessScope::AssignedSites],
        [Role::DISPATCHER, 'Dispatcher', 40, AccessScope::All],
        [Role::ADMIN_ASSISTANT, 'Admin Assistant', 50, AccessScope::All],
        [Role::ACCOUNTANT, 'Accountant', 60, AccessScope::All],
    ];

    private const ESTATE_ROLES = [
        [Role::COMMUNITY_SUPER_ADMIN, 'Community Super Admin', 5, AccessScope::All],
        [Role::PRESIDENT, 'President', 10, AccessScope::All],
        [Role::VICE_PRESIDENT, 'Vice President', 20, AccessScope::All],
        [Role::SECRETARY, 'Secretary', 30, AccessScope::All],
        [Role::PROPERTY_MANAGER, 'Property Manager', 40, AccessScope::All],
        [Role::TREASURER, 'Treasurer', 50, AccessScope::All],
        [Role::ESTATE_ADMIN_ASSISTANT, 'Admin Assistant', 60, AccessScope::All],

        /*
         * The estate's own Head of Security (M10 §2) — the community's, not
         * Gemini's. `AccessScope::All` like every other estate role: "all" here
         * means the whole of THIS estate, and tenancy is what confines it to one.
         * The scope enum is about breadth within a console, and no estate role
         * has ever been able to see another estate.
         */
        [Role::ESTATE_HEAD_OF_SECURITY, 'Head of Security', 70, AccessScope::All],
    ];

    /**
     * Gemini grid, verbatim from the wireframe.
     * Columns: Director, Ops Manager, Head of Security, Dispatcher, Admin Asst, Accountant
     */
    private const GEMINI_GRID = [
        'dashboard' => ['F', 'F', 'F', 'F', 'F', 'F'],
        'clients' => ['F', 'F', 'S', 'V', 'V', 'V'],
        // DERIVED (D-018). Dispatch appears in the approved sidebar but has no
        // row in the matrix screen. Derived from guard_workforce, its nearest
        // operational analogue, with the Accountant narrowed to no access:
        // where the sources are silent, take the more restrictive reading.
        'dispatch' => ['F', 'F', 'S', 'F', 'V', '-'],
        'guard_workforce' => ['F', 'F', 'S', 'F', 'V', 'V'],
        /*
         * A DERIVED APPROVER CELL, the same way D-063 gave the estate's payroll
         * one (D-084). The wireframe draws this row with no Approver tag, which
         * was harmless while Q-002 held every run short of approval and is not
         * now: the ruling unblocked payroll, and with no `A` anywhere in the row
         * nobody on the platform could approve a guard pay run at all. The tag
         * goes to the Director — board 29's own persona, and the role that
         * already holds Full here. The Accountant keeps plain Full.
         */
        'payroll_accounting' => ['A', 'V', '-', '-', '-', 'F'],
        'billing_subscriptions' => ['F', 'V', '-', '-', '-', 'F'],
        'cross_tenant_reports' => ['F', 'F', 'S', '-', '-', 'V'],
        'access_audit_log' => ['F', 'V', '-', '-', '-', '-'],
        /*
         * A DERIVED APPROVER CELL, and the one that opens the matrix (M10 §4).
         *
         * Changing what a role may do is an approve-level act (D-013), and until
         * now no Gemini role held approve on Platform settings — which is exactly
         * why the Role Access Matrix was a read-only table. The client now asks
         * for it to be editable by the Director, so the tag goes to the Director
         * and to nobody else: the one role that already holds Full here, and the
         * one the client names. `AccessLevel::Full` still does not imply approve
         * (D-008), so nothing else on the row moves.
         */
        'platform_settings' => ['A', '-', '-', '-', '-', '-'],
    ];

    /**
     * Estate grid.
     * Columns: Community Super Admin, President, VP, Secretary, Property Manager, Treasurer, Admin Asst
     *
     * The Community Super Admin column is not drawn in the wireframe — the
     * audit note describes it as full access within its own estate (D-009).
     *
     * Rows marked DERIVED did not exist as separate rows in the wireframe and
     * come from the Ruling 1 split; see D-014 for how each was derived.
     */
    private const ESTATE_GRID = [
        'dashboard' => ['F', 'F', 'F', 'F', 'F', 'F', 'V', 'V'],
        'estate_structure' => ['F', 'V', 'V', 'V', 'F', '-', '-', 'V'],
        /*
         * DERIVED APPROVER CELLS (D-053). Board 24 draws this row with no
         * Approver tag anywhere, because when it was drawn nothing in Residents
         * had an irreversible act in it. Board 31 then gave it one: approving a
         * unit claim binds a person to a household, which decides whose guest
         * passes they may issue and whose gate they may be admitted at, and no
         * edit afterwards unbinds the night somebody was let through.
         *
         * The tag goes to the two roles that already hold Full AND are the
         * screen's own reviewers — the Community Super Admin, who holds
         * everything within its estate (D-009), and the Property Manager, whose
         * name is in board 31's sidebar footer. The SECRETARY KEEPS PLAIN FULL
         * deliberately: they may prepare the decision, refuse a claim and ask
         * for a document, and may not commit the one act that cannot be walked
         * back. That is exactly the separation D-013 and D-008 exist for, and
         * `EstateResidentAccessTest` proves the Secretary is refused.
         */
        /*
         * THE EIGHTH COLUMN IS THE ESTATE'S OWN HEAD OF SECURITY (M10 §2).
         *
         * Its cells are mostly '-' and that is the point of the role. A person
         * responsible for the gate has no business in what a household owes, so
         * every money row refuses them outright: dues, payments, accounting,
         * vendor costs, maintenance budget and payroll are all '-'.
         *
         * Residents is 'V' and not more. They need to know WHO lives at a lot to
         * make sense of an alert from it and to check a visitor against a
         * household — and `Residents::standingOf()` already withholds the balance
         * from any viewer without `dues_ledger.view`, which this role does not
         * have. So the register they see carries names, lots and members, and
         * "Balance not shown" where the money would be.
         *
         * Governance is 'V' for the notices the section names, not for running an
         * election: elections administration is explicitly excluded.
         */
        'residents' => ['A', 'V', 'V', 'F', 'A', 'V', 'E', 'V'],
        /*
         * ONE DUES APPROVAL FOR THE COMMUNITY SUPER ADMIN, NOT ALL FIVE (M11 B3,
         * D-150, superseding the Approver tag D-141 gave this cell).
         *
         * M10 §3 asked for the Community Super Admin to "edit the estate's own
         * pricing: its fee schedules and dues rates", and Full carries no approval,
         * so D-141 made the cell an Approver. That tag was every approve-level act
         * on the module at once, which the client then called a bug: "Being able to
         * change a dues rate must not also grant reversing a posted charge run or
         * activating payment plans." The tag is now five permissions, and this cell
         * holds the one it was given for — `set_rates`: define a schedule, change
         * its rate from a month not yet billed, stop it.
         *
         * NOT `reverse_runs`: a reversal rewrites a posted month for every household
         * in it, which is the ledger's correction and the Treasurer's, and the
         * client named it as the power the rate must not carry. NOT
         * `activate_plans`: that lifts a household's gate restriction, a credit
         * decision the Treasurer makes against the household's own ledger. Nor the
         * flags or the reminder wording, which were only ever here by the same
         * accident. Before D-141 this cell approved nothing on the module; this
         * gives it back exactly the one power D-141 was for.
         *
         * The Treasurer keeps the Approver tag, which is all five. The Property
         * Manager's '-' is LOCKED by Ruling 1 and is not touched.
         */
        /*
         * THE ADMIN ASSISTANT HOLDS NO MONEY MODULE AT ALL (the user, 8 Oct 2026, M53 §1 Law 4, superseding the Entry
         * cells board 24 drew and M10 §5 kept). Signed in as the assistant, the console opened Bank reconciliation —
         * the estate's balance and the staff salary batch — and every supplier's bill, while arrears were refused:
         * "The gate is blocking the less sensitive thing and exposing the more sensitive one." The master reference
         * §8.1 the user quotes hides Payments & reconciliation, Accounting, Delinquency and Payroll from this role, and
         * "no amount of the vendors or maintenance should be shown to him". So the five money rows are '-' in the
         * seventh column: no nav item, no page, no figure. Keying receipts, charges and bills is the Treasurer's and
         * the Community Super Admin's.
         */
        /*
         * THE COMMUNITY SUPER ADMIN HOLDS EVERY APPROVAL (the user, 8 Oct 2026: "Make the Super Admin genuinely super",
         * master reference §5.3 "Community Super Admin: everything within the community", §8.1 ● on every module).
         * Signed in as the Super Admin, the console refused publishing a meeting, approving a bill and voiding one:
         * the column held Full without the approval on these rows. So the first cell of every row that has an approval
         * is now 'A'. This supersedes D-150's single dues approval (PRICE-02): the user's ruling is that nothing another
         * community role may do is refused to the Super Admin.
         */
        'dues_ledger' => ['A', 'V', 'V', '-', '-', 'A', '-', '-'],  // PM '-' : LOCKED by Ruling 1
        'payments' => ['A', 'V', 'V', '-', '-', 'A', '-', '-'],      // DERIVED
        'accounting_posting' => ['A', 'V', 'V', '-', '-', 'A', '-', '-'], // PM '-' : LOCKED
        'vendor_costs' => ['A', 'V', 'V', '-', 'V', 'A', '-', '-'],  // DERIVED, PM 'V' permitted
        'maintenance_budget' => ['A', 'V', 'V', '-', 'V', 'A', '-', '-'], // DERIVED, PM 'V' permitted
        /*
         * A DERIVED APPROVER CELL, the same way Residents got two (D-053).
         *
         * Board 24 draws this row with Full for the Community Super Admin and
         * the Treasurer, View for the two officers, and nothing else — and no
         * Approver tag anywhere. Board 15 then requires one in its own banner:
         * "Prepared by Tracey Reid — awaiting your approval. As a second
         * approver, this run cannot be disbursed until you review and approve
         * it." Disbursing is irreversible; the money leaves the bank.
         *
         * Read literally, the drawn row makes that flow unreachable. The
         * Treasurer prepares, and `Payroll::approvalRefusal()` refuses to let a
         * preparer approve their own run — so the only cell that can hold the
         * approval is the Community Super Admin's, and it is promoted from Full
         * to Full · Approver. The Treasurer keeps plain Full and prepares, which
         * is exactly the separation board 15 describes.
         */
        'payroll' => ['A', 'V', 'V', '-', '-', 'F', '-', '-'],
        /*
         * DERIVED APPROVER CELLS (D-086), the same way Residents got two (D-053).
         *
         * Board 24 draws this row with no Approver tag, because when it was
         * drawn nothing in Facilities kept anybody's money. The client's ruling
         * on the deposit door gave it one: forfeiting a deposit keeps a
         * resident's money as the estate's income, and it takes "the higher
         * verb" — `facilities.approve` — where taking and refunding take
         * `update`. Read literally, the drawn row has no cell that holds it, so
         * nobody in any estate could forfeit a deposit at all.
         *
         * The tag goes to the two roles that already hold Full: the Community
         * Super Admin, who holds everything within its estate (D-009), and the
         * Property Manager, whose screen the ruling says this is. The ADMIN
         * ASSISTANT KEEPS ENTRY deliberately — recording a deposit received or
         * returned is data entry, and keeping one is not.
         */
        'facilities' => ['A', 'V', 'V', 'V', 'A', 'V', 'E', 'V'],
        // The Super Admin certifies elections and publishes meetings, as the President and Vice President do (above).
        'governance' => ['A', 'A', 'A', 'F', '-', 'V', '-', 'V'],

        /*
         * SECURITY — the new module, and the new role's own (M10 §2).
         *
         * Full for the Community Super Admin, who holds everything within its
         * estate (D-009), and for the new Head of Security, whose screen this
         * is. 'V' for the President, the Vice President and the Property
         * Manager: a panic at a lot is something the committee and the person
         * who runs the estate need to SEE, and acknowledging one is a security
         * decision rather than a committee one.
         *
         * The Secretary, the Treasurer and the Admin Assistant get nothing.
         * Minutes, money and data entry have no bearing on who was let through a
         * gate, and this module carries the visitor log — a record of who
         * visited whom, which is the most personal thing on the estate console
         * and is not given out for tidiness.
         *
         * Columns: Super Admin, President, VP, Secretary, Property Manager,
         *          Treasurer, Admin Assistant, Head of Security
         */
        'security' => ['F', 'V', 'V', '-', 'V', '-', '-', 'F'],

        'reports' => ['F', 'F', 'F', 'V', 'V', 'F', '-', 'V'],
        /*
         * A DERIVED APPROVER CELL, the estate's half of the same change (M10 §4).
         *
         * Board 24 draws no Approver tag on Settings, and that absence was the
         * whole of the argument that the estate's matrix could not be edited: no
         * estate role held `estate.settings.approve`, so nobody could approve a
         * change to what a role may do. The client now names the Community Super
         * Admin as the person who creates and edits their estate's roles, so the
         * tag goes to that one cell. The President and Vice President keep View:
         * reading the matrix is not changing it, and a committee officer who
         * could re-grant their own column is the escalation D-013 exists to stop.
         */
        'settings' => ['A', 'V', 'V', '-', '-', '-', '-', '-'],
    ];

    public function run(): void
    {
        DB::connection('mysql')->transaction(function () {
            $this->seedPermissions();

            $gemini = $this->seedModules(Console::Gemini, $this->geminiModules());
            $estate = $this->seedModules(Console::Estate, $this->estateModules());

            $geminiRoles = $this->seedRoles(Console::Gemini, $this->geminiRoles());
            $estateRoles = $this->seedRoles(Console::Estate, $this->estateRoles());

            $this->seedGrid($this->geminiGrid(), $gemini, $geminiRoles);
            $this->seedGrid($this->estateGrid(), $estate, $estateRoles);
        });

        /*
         * COMPILED AND FLUSHED AFTER THE COMMIT, NOT INSIDE IT.
         *
         * spatie clears its permission cache whenever a role is saved or its
         * permissions synced, and inside the transaction above that clear runs
         * before the new rows are committed. A request arriving in that window
         * rebuilds the cache from the rows it can see — the OLD ones, under
         * REPEATABLE READ — and the cache then holds them for twenty-four hours
         * (config/permission.php). That is the stale-cache lockout the client
         * named in M10 §4, and it was this seeder's own latent copy of it. So the
         * compile happens on committed cells, in a transaction of its own so no
         * reader ever sees a role half re-granted, and one explicit flush after
         * THAT commit is the last word.
         */
        DB::connection('mysql')->transaction(function (): void {
            $this->syncSpatiePermissions();
            $this->retireSplitVerbs();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * One seeded role's default cells, keyed by module key, or null for a role
     * this seeder does not define (every role made at runtime).
     *
     * The ONE definition of "GeminiSecure's default" for a role. The seeder
     * writes it, and `RoleEditor::resetToDefault()` and `rbac:reset-role`
     * restore it. Each cell comes out exactly as `seedGrid()` writes it,
     * including the rule that a site-scoped role carries its scope on every cell
     * it holds.
     *
     * `acts` is the named acts the cell grants one by one (M11 B3) — empty for
     * every seeded cell but the few the grid writes with a `+` suffix.
     *
     * @return array<string, array{level: AccessLevel, can_approve: bool, scope: AccessScope, acts: list<string>}>|null
     */
    public function defaultsFor(string $roleName): ?array
    {
        $sets = [
            [Console::Gemini, $this->geminiRoles(), $this->geminiGrid()],
            [Console::Estate, $this->estateRoles(), $this->estateGrid()],
        ];

        foreach ($sets as [$console, $roles, $grid]) {
            $column = array_search($roleName, array_column($roles, 0), true);

            if ($column === false) {
                continue;
            }

            $roleScope = $roles[$column][3];
            $cells = [];

            foreach ($grid as $moduleKey => $row) {
                $cells[$moduleKey] = $this->cellFromPill($row[$column], $roleScope, $console, $moduleKey);
            }

            return $cells;
        }

        return null;
    }

    /** @return list<array{0: string, 1: string, 2: string|null, 3: int, 4: bool}> */
    protected function geminiModules(): array
    {
        return self::GEMINI_MODULES;
    }

    /** @return list<array{0: string, 1: string, 2: string|null, 3: int, 4: bool}> */
    protected function estateModules(): array
    {
        return self::ESTATE_MODULES;
    }

    /** @return list<array{0: string, 1: string, 2: int, 3: AccessScope}> */
    protected function geminiRoles(): array
    {
        return self::GEMINI_ROLES;
    }

    /** @return list<array{0: string, 1: string, 2: int, 3: AccessScope}> */
    protected function estateRoles(): array
    {
        return self::ESTATE_ROLES;
    }

    /** @return array<string, array<int, string>> */
    protected function geminiGrid(): array
    {
        return self::GEMINI_GRID;
    }

    /** @return array<string, array<int, string>> */
    protected function estateGrid(): array
    {
        return self::ESTATE_GRID;
    }

    /**
     * One pill as the cell it seeds.
     *
     * `F+set_rates` is the pill `F` plus the named act `set_rates`, granted on
     * its own. REFUSED, rather than seeded, when an act is not one of this
     * module's or is already implied by the pill: the grid is the platform's
     * definition of every role, and a cell that said more than it granted — or
     * granted what nobody could read off it — would be a grid nobody can check.
     *
     * @return array{level: AccessLevel, can_approve: bool, scope: AccessScope, acts: list<string>}
     */
    private function cellFromPill(string $pill, AccessScope $roleScope, Console $console, string $moduleKey): array
    {
        [$base, $suffix] = array_pad(explode('+', $pill, 2), 2, '');

        [$level, $canApprove, $scope] = self::CELLS[$base];

        // Head of Security carries its scope on every cell it holds,
        // not only the ones the wireframe drew as Scoped.
        if ($roleScope === AccessScope::AssignedSites && $level !== AccessLevel::None) {
            $scope = AccessScope::AssignedSites;
        }

        $acts = [];
        $known = NamedActs::of($console, $moduleKey);
        $verbs = array_map(static fn (PermissionVerb $verb): string => $verb->value, $level->verbs());

        foreach ($suffix === '' ? [] : explode(',', $suffix) as $act) {
            $act = trim($act);

            if (! isset($known[$act])) {
                throw new \LogicException("The grid gives {$console->value}.{$moduleKey} the named act '{$act}', which that module does not have.");
            }

            $parent = $known[$act]['verb'];

            if (($parent === PermissionVerb::Approve->value && $canApprove) || in_array($parent, $verbs, true)) {
                throw new \LogicException("The grid's pill '{$pill}' on {$console->value}.{$moduleKey} already grants '{$act}' through '{$parent}'.");
            }

            if (! in_array(PermissionVerb::View->value, $verbs, true)) {
                throw new \LogicException("The grid's pill '{$pill}' on {$console->value}.{$moduleKey} grants '{$act}' without view. Nobody can do what they cannot open.");
            }

            $acts[] = $act;
        }

        // In registry order, the order `RoleEditor` stores a saved cell's in, so a
        // reset lands on exactly the cell a re-seed writes.
        $acts = array_values(array_filter(array_keys($known), static fn (string $act): bool => in_array($act, $acts, true)));

        // Every act of a verb listed one by one IS that verb, and the grid should say so.
        foreach (array_unique(array_column($known, 'verb')) as $parent) {
            $under = NamedActs::under($console, $moduleKey, $parent);

            if ($under !== [] && array_diff($under, $acts) === []) {
                throw new \LogicException("The grid's pill '{$pill}' on {$console->value}.{$moduleKey} lists every act under '{$parent}'. Write the verb instead.");
            }
        }

        return ['level' => $level, 'can_approve' => $canApprove, 'scope' => $scope, 'acts' => $acts];
    }

    /**
     * One permission per console.module.verb — or, for a verb split into named
     * acts, one per act and none for the verb (M11 B3).
     *
     * The console segment matters: both consoles have a Dashboard, and without
     * it granting a Gemini role sight of its dashboard would silently grant
     * every estate role sight of theirs.
     *
     * A SPLIT VERB'S OWN PERMISSION IS NOT CREATED. Its whole power is its acts,
     * and `2026_09_24_200000_one_permission_per_power_where_one_verb_carried_several`
     * took the existing row out after moving every holder onto the acts. Re-creating it here would give `can:` a permission
     * nobody can be granted and a screen a name that means nothing. One that exists
     * again anyway is retired by `retireSplitVerbs()`.
     */
    private function seedPermissions(): void
    {
        $sets = [
            Console::Gemini->value => array_column($this->geminiModules(), 0),
            Console::Estate->value => array_column($this->estateModules(), 0),
        ];

        foreach ($sets as $console => $keys) {
            foreach ($keys as $moduleKey) {
                foreach (PermissionVerb::cases() as $verb) {
                    $split = NamedActs::under($console, $moduleKey, $verb);

                    if ($split === []) {
                        Permission::findOrCreate("{$console}.{$moduleKey}.{$verb->value}", 'web');

                        continue;
                    }

                    foreach ($split as $act) {
                        Permission::findOrCreate("{$console}.{$moduleKey}.{$act}", 'web');
                    }
                }
            }
        }
    }

    /**
     * A split verb's own permission, if one exists again, is retired again: the
     * accounts holding it directly are given every act it became, and it goes.
     *
     * HOW ONE COMES BACK. The M11 B3 migration deleted them. A release from before
     * it, put back without reversing the migration, has nothing to check but those
     * names, and its own seeder re-creates them and compiles every role onto them.
     * Deploying this code again does not run that migration a second time, so
     * without this the names would stay: asked by no route, held by no role once
     * `syncSpatiePermissions()` has recompiled every role from its cells, and
     * failing `gate:console`. After this, running whichever release's seeder is
     * current puts the permission set right, in either direction (STATE.md).
     *
     * AFTER the roles are recompiled, in the same transaction, so no reader sees a
     * role between its old grants and its new ones.
     */
    private function retireSplitVerbs(): void
    {
        $db = DB::connection('mysql');

        foreach (NamedActs::replacements() as $retired => $acts) {
            $old = Permission::query()->where('name', $retired)->where('guard_name', 'web')->first();

            if ($old === null) {
                continue;
            }

            foreach ($acts as $act) {
                Permission::findOrCreate($act, 'web');
            }

            $ids = Permission::query()->whereIn('name', $acts)->where('guard_name', 'web')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            foreach ($db->table('model_has_permissions')->where('permission_id', $old->getKey())->get(['model_type', 'model_id']) as $holder) {
                foreach ($ids as $id) {
                    $db->table('model_has_permissions')->insertOrIgnore([
                        'permission_id' => $id,
                        'model_type' => $holder->model_type,
                        'model_id' => $holder->model_id,
                    ]);
                }
            }

            $db->table('role_has_permissions')->where('permission_id', $old->getKey())->delete();
            $db->table('model_has_permissions')->where('permission_id', $old->getKey())->delete();
            $old->delete();
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string|null, 3: int, 4: bool}>  $definitions
     * @return array<string, Module> keyed by module key
     */
    private function seedModules(Console $console, array $definitions): array
    {
        $out = [];

        foreach ($definitions as [$key, $label, $section, $sort, $locked]) {
            $out[$key] = Module::updateOrCreate(
                ['key' => $key, 'console' => $console->value],
                [
                    'label' => $label,
                    'section' => $section,
                    'sort' => $sort,
                    'is_locked_financial' => $locked,
                ],
            );
        }

        return $out;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: AccessScope}>  $definitions
     * @return list<Role> in column order
     */
    private function seedRoles(Console $console, array $definitions): array
    {
        $out = [];

        foreach ($definitions as [$name, $label, $sort, $scope]) {
            // findOrCreate returns spatie's Contracts\Role, which knows
            // nothing about console, label or scope_default. named() narrows
            // it back to this application's Role.
            Role::findOrCreate($name, 'web');
            $role = Role::named($name);

            /*
             * `is_system` and a NULL tenant on every seeded role, on every run.
             *
             * These are the platform's own roles (M10 §4): never renamed, never
             * deleted, and — for the estate eight — shared by every estate, so
             * no estate may re-grant them. Writing both here rather than trusting
             * the migration's backfill means a role this seeder adds in future
             * arrives protected on the same run that creates it.
             *
             * THIS TOUCHES ONLY THE ROLES NAMED ABOVE. A role an estate or the
             * Director made at runtime is not in these lists, so a re-seed leaves
             * it, its cells and its compiled permissions exactly as they were.
             */
            $role->forceFill([
                'console' => $console->value,
                'tenant_id' => null,
                'is_system' => true,
                'label' => $label,
                'sort' => $sort,
                'scope_default' => $scope->value,
            ]);

            /*
             * An estate role is never marked as edited: nothing lets an estate
             * change one, and one marked by hand would stop following the grid
             * for every community at once. See the class docblock.
             */
            if ($console === Console::Estate) {
                $role->forceFill(['permissions_edited_at' => null, 'permissions_edited_by_id' => null]);
            }

            $role->save();

            $out[] = $role;
        }

        return $out;
    }

    /**
     * @param  array<string, array<int, string>>  $grid
     * @param  array<string, Module>  $modules
     * @param  array<int, Role>  $roles
     */
    private function seedGrid(array $grid, array $modules, array $roles): void
    {
        $kept = $this->cellsTheDirectorOwns($roles);

        foreach ($grid as $moduleKey => $row) {
            $module = $modules[$moduleKey];

            foreach ($row as $i => $pill) {
                $role = $roles[$i];
                [
                    'level' => $level,
                    'can_approve' => $canApprove,
                    'scope' => $scope,
                    'acts' => $acts,
                ] = $this->cellFromPill($pill, $role->scope_default, $module->console, $module->key);

                /*
                 * Ruling 1 is enforced here, not merely represented in data.
                 * If a future edit to the grid above granted the Property
                 * Manager a locked financial module, this would refuse it
                 * rather than seeding a breach. Checked before the skip below:
                 * the grid itself must never breach it, whoever edited what.
                 * A named act on its own is a grant too (M11 B3).
                 */
                if ($module->isLockedFor($role) && ($level !== AccessLevel::None || $canApprove || $acts !== [])) {
                    throw new \LogicException(
                        "Refusing to grant {$role->name} '{$level->value}' on locked financial module ".
                        "'{$module->key}'. See DECISIONS.md D-010."
                    );
                }

                // A seeded Gemini role the Director changed keeps the cell it
                // has. A cell it does not have yet (a module the grid has
                // just introduced) is still written below, at the grid's value.
                if (isset($kept[$role->id][$module->id])) {
                    continue;
                }

                // `verbs` null: a seeded cell is always one of the four presets.
                // A custom verb list can only come from a runtime edit, and a
                // role carrying one is marked edited and skipped above. `acts`
                // is null unless the pill names some (M11 B3).
                RoleModuleAccess::updateOrCreate(
                    ['role_id' => $role->id, 'module_id' => $module->id],
                    [
                        'level' => $level->value,
                        'verbs' => null,
                        'acts' => $acts === [] ? null : $acts,
                        'can_approve' => $canApprove,
                        'scope' => $scope->value,
                    ],
                );
            }
        }
    }

    /**
     * The cells this run must leave exactly as they are: every existing cell of
     * a seeded GEMINI role marked as edited at runtime.
     *
     * Gemini only. The console is checked here as well as the mark, so an
     * estate role can never be exempted from the grid, even by a mark written
     * into the database by hand. `seedRoles()` has already cleared any such mark.
     *
     * @param  array<int, Role>  $roles
     * @return array<int, array<int, true>> role id => module id => true
     */
    private function cellsTheDirectorOwns(array $roles): array
    {
        $edited = array_values(array_filter(
            $roles,
            static fn (Role $role): bool => $role->console === Console::Gemini && $role->permissions_edited_at !== null,
        ));

        if ($edited === []) {
            return [];
        }

        $kept = [];

        $cells = RoleModuleAccess::query()
            ->whereIn('role_id', array_map(static fn (Role $role): int => $role->id, $edited))
            ->get(['role_id', 'module_id']);

        foreach ($cells as $cell) {
            $kept[(int) $cell->role_id][(int) $cell->module_id] = true;
        }

        return $kept;
    }

    /**
     * Project the matrix onto spatie permissions so can() works.
     *
     * Every role, from its cells. For a role whose cells were left alone, this
     * recompiles exactly the list `RoleEditor` compiled from those same cells,
     * plus whatever a newly added cell grants. That keeps a Director's change
     * as they left it, and still lets a new module reach the role.
     */
    private function syncSpatiePermissions(): void
    {
        foreach (Role::with('moduleAccess.module')->get() as $role) {
            $names = $role->moduleAccess
                ->flatMap(fn (RoleModuleAccess $a) => $a->permissionNames())
                ->unique()
                ->values()
                ->all();

            $role->syncPermissions($names);
        }
    }
}
